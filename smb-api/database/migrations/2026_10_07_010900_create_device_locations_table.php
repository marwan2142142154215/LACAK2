<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->double('latitude');
            $table->double('longitude');
            $table->float('accuracy')->nullable();
            // §70: jujur soal sumber data lokasi — bukan diklaim real-time GPS kalau sebenarnya last-known/network.
            $table->enum('source', ['GPS', 'NETWORK', 'FUSED', 'LAST_KNOWN']);
            $table->timestamp('recorded_at');
            $table->timestamp('received_at');
            $table->foreignUuid('requested_by_command_id')->nullable()
                ->constrained('device_commands')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_locations');
    }
};
