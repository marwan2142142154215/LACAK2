<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignUuid('command_id')->nullable()
                ->constrained('device_commands')->nullOnDelete();
            $table->enum('camera_facing', ['FRONT', 'BACK']);
            // Path di DigitalOcean Spaces — bukan URL publik permanen (§28). Signed URL dibuat on-demand.
            $table->string('storage_path', 500);
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256_hash', 64);
            $table->timestamp('captured_at');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            $table->index(['device_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_media');
    }
};
