<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            // UUID = device_id resmi (§18). Immutable selama lifecycle perangkat.
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->restrictOnDelete();
            $table->string('name', 150);

            // Status dihitung SERVER-SIDE dari heartbeat timestamp (§6), bukan klaim client.
            $table->enum('status', ['ONLINE', 'DEGRADED', 'OFFLINE', 'UNKNOWN', 'LOCKED'])
                ->default('UNKNOWN');

            // §7: true hanya jika Device Owner/Android Enterprise aktif & terverifikasi — jujur, tidak diasumsikan.
            $table->boolean('is_managed')->default(false);

            $table->smallInteger('android_api_level')->nullable();
            $table->string('android_version', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('manufacturer', 50)->nullable();
            $table->string('model', 100)->nullable();

            // §67: snapshot capability report terakhir dari device (camera_available, location_available, dst)
            $table->jsonb('capability_report')->nullable();

            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->foreignUuid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'team_id', 'status']);
            $table->index('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
