<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §50/§51: metadata versi APK untuk Download Center & update strategy.
        Schema::create('app_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('app_name', ['SMB_LACAK', 'SMB_MASTER']);
            $table->string('version', 20);
            $table->integer('version_code');
            $table->smallInteger('min_supported_android_api');
            $table->text('release_notes')->nullable();
            $table->string('download_path', 500);
            $table->string('checksum_sha256', 64);
            $table->timestamp('released_at');
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['app_name', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
