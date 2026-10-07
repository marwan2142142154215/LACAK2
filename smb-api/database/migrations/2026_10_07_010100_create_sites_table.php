<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            // Unique via partial index di bawah (bukan ->unique() biasa) — kode bisa dipakai
            // ulang setelah site di-soft-delete (§35, lihat juga migration users).
            $table->string('code', 30);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        DB::statement('CREATE UNIQUE INDEX sites_code_unique ON sites (code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
