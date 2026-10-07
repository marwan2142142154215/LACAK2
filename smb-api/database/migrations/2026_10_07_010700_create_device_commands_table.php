<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            // UUID = command_id resmi (§20)
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $table->string('command_type', 30);
            $table->jsonb('payload')->nullable();

            // §22: idempotency. Constraint UNIQUE di level DB (bukan hanya cek aplikasi) mencegah
            // command duplikat tereksekusi dua kali meski terjadi race condition / reconnect.
            $table->string('idempotency_key', 100);

            $table->enum('status', [
                'PENDING', 'QUEUED', 'SENT', 'DELIVERED', 'RECEIVED',
                'EXECUTING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED',
            ])->default('PENDING');

            // §69/§70: alasan gagal yang jujur & spesifik, bukan generic.
            $table->string('failure_reason', 255)->nullable();

            $table->enum('created_by_type', ['USER', 'TELEGRAM', 'SYSTEM']);
            $table->uuid('created_by_id')->nullable();

            // Session yang dituju saat SENT — AdonisJS wajib validasi ulang sebelum push (§21).
            $table->foreignUuid('device_session_id')->nullable()
                ->constrained('device_sessions')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'idempotency_key']);
            $table->index(['device_id', 'status', 'created_at']);
            $table->index('expires_at');
            $table->index('command_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
