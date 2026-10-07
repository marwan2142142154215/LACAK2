<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('scope_type', ['DEVICE', 'TEAM', 'SITE', 'GLOBAL']);
            $table->uuid('scope_id')->nullable(); // null jika GLOBAL
            $table->string('policy_key', 100);
            $table->jsonb('policy_value');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'policy_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_policies');
    }
};
