<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * CATATAN: tidak ada factory User/dummy data di sini secara sengaja (§66 no fake data
     * sebagai pengganti produksi). Hanya seed struktural (role & permission) yang WAJIB ada
     * di setiap environment. Super admin pertama dibuat manual via `php artisan tinker` atau
     * command khusus saat deployment (didokumentasikan di docs/deployment.md, PHASE 24).
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);
    }
}
