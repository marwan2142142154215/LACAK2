<?php

use App\Models\Device;
use App\Models\TelegramAccount;
use App\Models\TelegramCommandConfirmation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const TG_SECRET = 'test-webhook-secret';

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config()->set('services.telegram.webhook_secret', TG_SECRET);
    config()->set('services.telegram.bot_token', 'test-bot-token');
    Http::fake([
        '*/api/v1/internal/commands/dispatch' => Http::response(['success' => true], 200),
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);
});

function sendTelegramMessage(string $text, int $telegramUserId = 111222333, int $chatId = 111222333, ?string $username = 'budi'): \Illuminate\Testing\TestResponse
{
    return test()->withHeader('X-Telegram-Bot-Api-Secret-Token', TG_SECRET)
        ->postJson('/api/v1/telegram/webhook', [
            'update_id' => random_int(1, 999999),
            'message' => [
                'chat' => ['id' => $chatId],
                'from' => ['id' => $telegramUserId, 'username' => $username],
                'text' => $text,
            ],
        ]);
}

it('rejects a webhook call with a wrong or missing secret token', function () {
    $response = test()->postJson('/api/v1/telegram/webhook', [
        'message' => ['chat' => ['id' => 1], 'from' => ['id' => 1], 'text' => '/start'],
    ]);

    $response->assertStatus(403);

    $response2 = test()->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')
        ->postJson('/api/v1/telegram/webhook', [
            'message' => ['chat' => ['id' => 1], 'from' => ['id' => 1], 'text' => '/start'],
        ]);

    $response2->assertStatus(403);
});

it('creates a PENDING telegram_account on /start and replies honestly that approval is needed', function () {
    sendTelegramMessage('/start')->assertOk();

    $account = TelegramAccount::where('telegram_id', 111222333)->first();
    expect($account)->not->toBeNull();
    expect($account->status)->toBe('PENDING');
    expect($account->telegram_username)->toBe('budi');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
        && str_contains($request['text'], 'Menunggu admin'));
});

it('rejects device commands from an account that is not yet approved', function () {
    sendTelegramMessage('/start');

    sendTelegramMessage('/lock Kasir 1')->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'belum disetujui'));
    expect(\App\Models\DeviceCommand::count())->toBe(0);
});

it('requires step-up confirmation before dispatching LOCK, then dispatches on correct /confirm', function () {
    $admin = User::factory()->create();
    $admin->assignRole('ADMIN');
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');
    $device = Device::factory()->create(['name' => 'Kasir 1', 'status' => 'ONLINE']);

    $account = TelegramAccount::create([
        'telegram_id' => 111222333,
        'telegram_username' => 'budi',
        'user_id' => $operator->id,
        'status' => 'APPROVED',
        'step_up_required' => true,
        'approved_by' => $admin->id,
        'approved_at' => now(),
    ]);

    sendTelegramMessage('/lock Kasir 1')->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(0);
    $confirmation = TelegramCommandConfirmation::where('telegram_account_id', $account->id)->first();
    expect($confirmation)->not->toBeNull();
    expect($confirmation->command_type)->toBe('LOCK');

    // Kode plaintext dikirim lewat sendMessage — ambil dari pesan terakhir yang di-mock.
    $sentText = null;
    Http::assertSent(function ($request) use (&$sentText) {
        if ($request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage' && str_contains($request['text'] ?? '', '/confirm')) {
            $sentText = $request['text'];
        }

        return true;
    });
    preg_match('/\/confirm (\d{6})/', $sentText, $m);
    $code = $m[1];

    sendTelegramMessage("/confirm {$code}")->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(1);
    $command = \App\Models\DeviceCommand::first();
    expect($command->command_type)->toBe('LOCK');
    expect($command->created_by_type)->toBe('TELEGRAM');
    expect($command->created_by_id)->toBe($account->id);
    expect($confirmation->refresh()->used_at)->not->toBeNull();
});

it('rejects a wrong confirmation code without dispatching and tracks remaining attempts', function () {
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');
    $device = Device::factory()->create(['name' => 'Kasir 1']);

    $account = TelegramAccount::create([
        'telegram_id' => 111222333,
        'user_id' => $operator->id,
        'status' => 'APPROVED',
        'step_up_required' => true,
    ]);

    TelegramCommandConfirmation::create([
        'telegram_account_id' => $account->id,
        'device_id' => $device->id,
        'command_type' => 'LOCK',
        'code_hash' => Hash::make('654321'),
        'expires_at' => now()->addMinutes(5),
        'max_attempts' => 3,
    ]);

    sendTelegramMessage('/confirm 000000')->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Percobaan tersisa: 2'));
});

it('dispatches immediately without confirmation when step_up_required is false', function () {
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');
    $device = Device::factory()->create(['name' => 'Kasir 1', 'status' => 'ONLINE']);

    TelegramAccount::create([
        'telegram_id' => 111222333,
        'user_id' => $operator->id,
        'status' => 'APPROVED',
        'step_up_required' => false,
    ]);

    sendTelegramMessage('/unlock Kasir 1')->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(1);
    expect(\App\Models\DeviceCommand::first()->command_type)->toBe('UNLOCK');
});

it('rejects a command the linked user lacks permission for (VIEWER cannot lock)', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('VIEWER');
    Device::factory()->create(['name' => 'Kasir 1']);

    TelegramAccount::create([
        'telegram_id' => 111222333,
        'user_id' => $viewer->id,
        'status' => 'APPROVED',
        'step_up_required' => false,
    ]);

    sendTelegramMessage('/lock Kasir 1')->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'tidak memiliki izin'));
});

it('rejects commands from a revoked telegram account', function () {
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');
    Device::factory()->create(['name' => 'Kasir 1']);

    TelegramAccount::create([
        'telegram_id' => 111222333,
        'user_id' => $operator->id,
        'status' => 'REVOKED',
        'step_up_required' => false,
    ]);

    sendTelegramMessage('/lock Kasir 1')->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(0);
});

it('replies with an honest unsupported-feature message for /camera instead of faking success', function () {
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');

    TelegramAccount::create([
        'telegram_id' => 111222333,
        'user_id' => $operator->id,
        'status' => 'APPROVED',
        'step_up_required' => false,
    ]);

    sendTelegramMessage('/camera Kasir 1')->assertOk();

    expect(\App\Models\DeviceCommand::count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'belum didukung'));
});

it('replies with help text for an unknown command', function () {
    sendTelegramMessage('/unknown-xyz')->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Perintah yang tersedia'));
});
