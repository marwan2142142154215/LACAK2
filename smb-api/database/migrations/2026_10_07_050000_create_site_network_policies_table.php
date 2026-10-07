<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §98 — whitelist jaringan per Site. `network_type` membedakan cara pencocokan
 * (`IP` = exact match, `CIDR` = range network) — field generik `value` menyimpan
 * representasi string-nya (IP tunggal atau notasi CIDR "203.0.113.0/24").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_network_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->enum('network_type', ['IP', 'CIDR'])->default('IP');
            $table->string('value', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_network_policies');
    }
};
