<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->unique()->constrained('devices')->cascadeOnDelete();
            // Hash dari secret device. TIDAK PERNAH plaintext (§39).
            $table->string('credential_hash', 255);
            $table->string('public_token_id', 100)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
