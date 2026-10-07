<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_heartbeats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->smallInteger('battery_level')->nullable();
            $table->string('network_type', 20)->nullable();
            $table->string('signal_status', 20)->nullable();
            $table->string('connection_state', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('android_version', 20)->nullable();
            // Waktu di device — TIDAK dipakai untuk menghitung status (anti clock-skew spoof).
            $table->timestamp('recorded_at');
            // Waktu server terima — dipakai untuk menghitung ONLINE/DEGRADED/OFFLINE (§6).
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['device_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_heartbeats');
    }
};
