<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // §30: ID Telegram numerik, BUKAN username — username bisa diganti siapa saja.
            $table->bigInteger('telegram_id')->unique();
            $table->string('telegram_username', 50)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['PENDING', 'APPROVED', 'REVOKED'])->default('PENDING');
            // §30: step-up auth untuk command sensitif, default wajib true.
            $table->boolean('step_up_required')->default(true);
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};
