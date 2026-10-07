<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §103/§105/§106 — satu row per "episode" pelanggaran (NORMAL -> VIOLATION -> RESOLVED),
 * BUKAN satu row per heartbeat (§105 — anti alert-spam: dedup berdasarkan row yang masih
 * terbuka/`resolved_at IS NULL`, bukan mengirim alert di setiap heartbeat yang
 * terdeteksi melanggar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_network_violations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('observed_ip', 45)->nullable();
            $table->enum('policy_status', ['BLOCKED', 'UNKNOWN'])->default('BLOCKED');
            $table->enum('severity', ['INFO', 'WARNING', 'HIGH', 'CRITICAL'])->default('WARNING');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('alert_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // §105: "episode terbuka" dicari lewat device_id + resolved_at IS NULL — index ini
            // yang dipakai NetworkViolationTracker di setiap heartbeat, HARUS cepat.
            $table->index(['device_id', 'resolved_at']);
            $table->index(['site_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_network_violations');
    }
};
