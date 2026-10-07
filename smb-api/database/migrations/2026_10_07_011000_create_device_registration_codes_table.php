<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_registration_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Kode itu sendiri di-hash — tidak disimpan plaintext (§17/§39).
            $table->string('code_hash', 255)->unique();
            $table->foreignUuid('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            // NULL = belum dipakai. Dicek via SELECT ... FOR UPDATE saat validasi agar tidak ada
            // race condition dua device klaim kode yang sama bersamaan (§17/§21).
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('used_by_device_id')->nullable()
                ->constrained('devices')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_registration_codes');
    }
};
