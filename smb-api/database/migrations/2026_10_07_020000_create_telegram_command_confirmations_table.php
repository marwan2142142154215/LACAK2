<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §30 — step-up confirmation untuk command sensitif yang diminta lewat Telegram
        // (LOCK/UNLOCK/LOCATION_REQUEST/CAMERA_REQUEST). Command TIDAK langsung dibuat saat
        // user mengetik /lock dkk — disimpan dulu di sini (hash kode, bukan plaintext),
        // baru benar2 dibuat via DeviceCommandDispatcher saat /confirm <code> cocok.
        Schema::create('telegram_command_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('telegram_account_id')->constrained('telegram_accounts')->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('command_type', 30);
            $table->jsonb('payload')->nullable();
            // §30/§39: HASHED, tidak pernah plaintext.
            $table->string('code_hash', 255);
            $table->timestamp('expires_at');
            $table->smallInteger('attempt_count')->default(0);
            $table->smallInteger('max_attempts')->default(3);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['telegram_account_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_command_confirmations');
    }
};
