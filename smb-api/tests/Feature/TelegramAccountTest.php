<?php

use App\Models\TelegramAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function telegramAccountToken(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('lets ADMIN list telegram accounts', function () {
    TelegramAccount::create(['telegram_id' => 1, 'status' => 'PENDING']);

    $this->withHeader('Authorization', 'Bearer '.telegramAccountToken('ADMIN'))
        ->getJson('/api/v1/telegram/accounts')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('rejects VIEWER from listing telegram accounts', function () {
    TelegramAccount::create(['telegram_id' => 1, 'status' => 'PENDING']);

    $this->withHeader('Authorization', 'Bearer '.telegramAccountToken('VIEWER'))
        ->getJson('/api/v1/telegram/accounts')
        ->assertStatus(403);
});

it('lets ADMIN approve a pending account and link it to a user', function () {
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');
    $account = TelegramAccount::create(['telegram_id' => 42, 'status' => 'PENDING']);

    $response = $this->withHeader('Authorization', 'Bearer '.telegramAccountToken('ADMIN'))
        ->postJson("/api/v1/telegram/accounts/{$account->id}/approve", ['user_id' => $operator->id]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'APPROVED')
        ->assertJsonPath('data.user_id', $operator->id);

    expect($account->refresh()->isApproved())->toBeTrue();
});

it('rejects approval with a non-existent user_id', function () {
    $account = TelegramAccount::create(['telegram_id' => 42, 'status' => 'PENDING']);

    $this->withHeader('Authorization', 'Bearer '.telegramAccountToken('ADMIN'))
        ->postJson("/api/v1/telegram/accounts/{$account->id}/approve", ['user_id' => (string) \Illuminate\Support\Str::uuid()])
        ->assertStatus(422);
});

it('lets ADMIN revoke an approved account', function () {
    $operator = User::factory()->create();
    $operator->assignRole('OPERATOR');
    $account = TelegramAccount::create([
        'telegram_id' => 42,
        'user_id' => $operator->id,
        'status' => 'APPROVED',
    ]);

    $this->withHeader('Authorization', 'Bearer '.telegramAccountToken('ADMIN'))
        ->postJson("/api/v1/telegram/accounts/{$account->id}/revoke")
        ->assertOk()
        ->assertJsonPath('data.status', 'REVOKED');

    expect($account->refresh()->isRevoked())->toBeTrue();
});

it('rejects OPERATOR from managing telegram accounts', function () {
    $account = TelegramAccount::create(['telegram_id' => 42, 'status' => 'PENDING']);

    $this->withHeader('Authorization', 'Bearer '.telegramAccountToken('OPERATOR'))
        ->postJson("/api/v1/telegram/accounts/{$account->id}/revoke")
        ->assertStatus(403);
});
