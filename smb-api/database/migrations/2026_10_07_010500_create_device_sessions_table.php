<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('session_token_hash', 255);
            $table->enum('connection_type', ['WEBSOCKET', 'HTTPS_POLL']);
            $table->string('gateway_node', 100)->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->string('disconnect_reason', 100)->nullable();
            $table->timestamps();

            $table->index('last_activity_at');
            $table->index(['device_id', 'disconnected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
