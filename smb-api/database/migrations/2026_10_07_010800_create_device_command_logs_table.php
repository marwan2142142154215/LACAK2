<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail tiap transisi status command (beda dari device_commands yang mutable current-state)
        Schema::create('device_command_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('command_id')->constrained('device_commands')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->enum('actor', ['DEVICE', 'GATEWAY', 'SYSTEM', 'USER']);
            $table->timestamp('created_at')->useCurrent();

            $table->index('command_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_command_logs');
    }
};
