<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            // §24: HASHED, tidak pernah plaintext.
            $table->string('otp_hash', 255);
            $table->string('purpose', 30)->default('UNLOCK');
            $table->timestamp('expires_at');
            $table->smallInteger('attempt_count')->default(0);
            $table->smallInteger('max_attempts')->default(5);
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['device_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_otps');
    }
};
