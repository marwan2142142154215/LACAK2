<?php

namespace App\Services;

use App\Models\Device;
use App\Models\TelegramAccount;
use App\Models\TelegramCommandConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * §29/§30 — Telegram sebagai KANAL TAMBAHAN untuk aksi yang SAMA PERSIS otorisasinya
 * dengan dashboard (permission RBAC user yang di-link, §40), BUKAN jalur pintas (§7/§16/
 * §27 — tidak ada bypass authorization lewat bot). Bot tidak punya identitas/permission
 * sendiri — semua aksi memakai permission user yang telegram_accounts-nya APPROVED.
 *
 * CATATAN JUJUR: "step-up confirmation" (/confirm <code>) di sini adalah pola
 * confirm-before-execute (mencegah command sensitif terkirim dari satu ketikan/tap yang
 * tidak sengaja) — BUKAN MFA faktor kedua yang independen, karena kode ditampilkan di
 * chat yang sama. Didokumentasikan apa adanya, tidak diklaim sebagai MFA sungguhan.
 */
class TelegramCommandHandler
{
    /** Command yang butuh permission + bisa di-step-up. command => [permission, dispatcherCommandType]. */
    private const ACTION_COMMANDS = [
        '/lock' => ['devices.lock', 'LOCK'],
        '/unlock' => ['devices.unlock', 'UNLOCK'],
        '/location' => ['devices.location', 'LOCATION_REQUEST'],
    ];

    public function __construct(
        private TelegramBotClient $bot,
        private DeviceCommandDispatcher $dispatcher,
    ) {}

    /**
     * @param array{chat?: array, from?: array, text?: string} $message Objek "message" dari Telegram update.
     */
    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $telegramUserId = $message['from']['id'] ?? null;
        $username = $message['from']['username'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! $chatId || ! $telegramUserId || $text === '') {
            return;
        }

        $account = TelegramAccount::query()->firstOrCreate(
            ['telegram_id' => $telegramUserId],
            ['telegram_username' => $username, 'status' => 'PENDING'],
        );

        if ($account->telegram_username !== $username) {
            $account->forceFill(['telegram_username' => $username])->save();
        }

        [$command, $argument] = $this->splitCommand($text);

        $reply = match (true) {
            $command === '/start' => $this->handleStart($account),
            $command === '/status' => $this->handleStatus($account),
            $command === '/devices' => $this->handleDevices($account),
            $command === '/camera' => 'Permintaan foto via Telegram belum didukung saat ini — gunakan dashboard web (menu Device). Keterbatasan ini didokumentasikan, bukan dibuat seolah berhasil.',
            $command === '/confirm' => $this->handleConfirm($account, $argument),
            array_key_exists($command, self::ACTION_COMMANDS) => $this->handleAction($account, $command, $argument),
            default => $this->helpText(),
        };

        $this->bot->sendMessage((int) $chatId, $reply);
    }

    private function splitCommand(string $text): array
    {
        $parts = preg_split('/\s+/', $text, 2);
        $command = strtolower($parts[0]);
        $argument = trim($parts[1] ?? '');

        return [$command, $argument];
    }

    private function helpText(): string
    {
        return "Perintah yang tersedia:\n"
            ."/status — cek status tautan akun\n"
            ."/devices — daftar device & status\n"
            ."/lock &lt;nama device&gt; — kunci device\n"
            ."/unlock &lt;nama device&gt; — buka kunci device\n"
            ."/location &lt;nama device&gt; — minta lokasi terbaru\n"
            .'/confirm <kode> — konfirmasi perintah sensitif';
    }

    private function handleStart(TelegramAccount $account): string
    {
        if ($account->isApproved()) {
            return "Akun Telegram Anda sudah tertaut sebagai {$account->user->name}. Ketik /status atau /devices.";
        }

        if ($account->isRevoked()) {
            return 'Akses Telegram Anda telah dicabut oleh admin. Hubungi admin jika ini keliru.';
        }

        return "Permintaan tautan tercatat. ID Telegram Anda: {$account->telegram_id}. Menunggu admin menyetujui akun ini dari dashboard sebelum Anda bisa memakai perintah device.";
    }

    private function handleStatus(TelegramAccount $account): string
    {
        return match ($account->status) {
            'APPROVED' => "Status: APPROVED, tertaut ke {$account->user->name}. Step-up confirmation: ".($account->step_up_required ? 'AKTIF' : 'NONAKTIF').'.',
            'REVOKED' => 'Status: REVOKED — akses dicabut admin.',
            default => 'Status: PENDING — menunggu persetujuan admin. Ketik /start untuk melihat ID Telegram Anda.',
        };
    }

    private function requireApprovedWithPermission(TelegramAccount $account, string $permission): ?string
    {
        if (! $account->isApproved()) {
            return 'Akun Telegram Anda belum disetujui admin. Ketik /start untuk info lebih lanjut.';
        }

        if (! $account->user->can($permission)) {
            return 'Anda tidak memiliki izin untuk melakukan ini (permission: '.$permission.').';
        }

        return null;
    }

    private function handleDevices(TelegramAccount $account): string
    {
        if ($error = $this->requireApprovedWithPermission($account, 'devices.view')) {
            return $error;
        }

        $devices = Device::query()->where('is_active', true)->orderBy('name')->limit(20)->get(['name', 'status']);

        if ($devices->isEmpty()) {
            return 'Belum ada device terdaftar.';
        }

        return "Device (maks 20):\n".$devices->map(fn ($d) => "- {$d->name}: {$d->status}")->implode("\n");
    }

    /**
     * @return array{0: Device|null, 1: string|null} [$device, $errorMessage]
     */
    private function findDeviceByName(string $name): array
    {
        if ($name === '') {
            return [null, 'Sebutkan nama device, contoh: /lock Kasir 1'];
        }

        $exact = Device::query()->where('is_active', true)->whereRaw('lower(name) = lower(?)', [$name])->first();
        if ($exact) {
            return [$exact, null];
        }

        $matches = Device::query()->where('is_active', true)->where('name', 'ilike', "%{$name}%")->limit(6)->get();

        if ($matches->isEmpty()) {
            return [null, "Device tidak ditemukan: {$name}"];
        }

        if ($matches->count() > 1) {
            $names = $matches->pluck('name')->implode(', ');

            return [null, "Lebih dari satu device cocok ({$names}). Sebutkan nama yang lebih spesifik."];
        }

        return [$matches->first(), null];
    }

    private function handleAction(TelegramAccount $account, string $command, string $argument): string
    {
        [$permission, $commandType] = self::ACTION_COMMANDS[$command];

        if ($error = $this->requireApprovedWithPermission($account, $permission)) {
            return $error;
        }

        [$device, $error] = $this->findDeviceByName($argument);
        if ($error) {
            return $error;
        }

        if ($commandType === 'LOCK' && $device->status === 'LOCKED') {
            return "Device \"{$device->name}\" sudah dalam status LOCKED.";
        }

        $payload = $commandType === 'LOCK' ? ['message' => 'Segera kembali ke tempat asal anda'] : null;

        if (! $account->step_up_required) {
            [$cmd] = $this->dispatcher->dispatch($device, $commandType, $payload, null, 60, $account->user, 'TELEGRAM', $account->id);

            return "Perintah {$commandType} dikirim ke \"{$device->name}\" (command #{$cmd->id}).";
        }

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        TelegramCommandConfirmation::create([
            'telegram_account_id' => $account->id,
            'device_id' => $device->id,
            'command_type' => $commandType,
            'payload' => $payload,
            'code_hash' => Hash::make($plainCode),
            'expires_at' => now()->addMinutes(5),
            'max_attempts' => 3,
        ]);

        return "Konfirmasi diperlukan untuk {$commandType} pada \"{$device->name}\".\nKetik: /confirm {$plainCode}\n(berlaku 5 menit)";
    }

    private function handleConfirm(TelegramAccount $account, string $code): string
    {
        if ($code === '') {
            return 'Sertakan kode konfirmasi, contoh: /confirm 123456';
        }

        $result = DB::transaction(function () use ($account, $code) {
            $confirmation = TelegramCommandConfirmation::query()
                ->where('telegram_account_id', $account->id)
                ->whereNull('used_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $confirmation) {
                return ['error' => 'Tidak ada konfirmasi yang menunggu.'];
            }

            if ($confirmation->isExpired()) {
                return ['error' => 'Kode konfirmasi sudah kedaluwarsa. Ulangi perintahnya dari awal.'];
            }

            if ($confirmation->attemptsExhausted()) {
                return ['error' => 'Kode konfirmasi sudah melebihi batas percobaan. Ulangi perintahnya dari awal.'];
            }

            if (! Hash::check($code, $confirmation->code_hash)) {
                $confirmation->increment('attempt_count');
                $remaining = max(0, $confirmation->max_attempts - $confirmation->attempt_count);

                return ['error' => "Kode salah. Percobaan tersisa: {$remaining}."];
            }

            $confirmation->forceFill(['used_at' => now()])->save();

            return ['confirmation' => $confirmation];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        /** @var TelegramCommandConfirmation $confirmation */
        $confirmation = $result['confirmation'];
        $device = $confirmation->device;

        [$cmd] = $this->dispatcher->dispatch(
            $device,
            $confirmation->command_type,
            $confirmation->payload,
            (string) Str::uuid(),
            60,
            $account->user,
            'TELEGRAM',
            $account->id,
        );

        return "Dikonfirmasi. Perintah {$confirmation->command_type} dikirim ke \"{$device->name}\" (command #{$cmd->id}).";
    }
}
