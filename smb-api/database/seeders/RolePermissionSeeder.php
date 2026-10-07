<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * §40 — RBAC baseline. Idempotent (firstOrCreate) supaya aman dijalankan ulang
 * di deployment manapun tanpa membuat duplikat atau menghapus assignment yang ada.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    protected array $roleMatrix = [
        'SUPER_ADMIN' => ['*'],
        'ADMIN' => [
            'devices.view', 'devices.create', 'devices.update', 'devices.delete',
            'devices.lock', 'devices.unlock', 'devices.location', 'devices.camera', 'devices.command',
            'sites.manage', 'teams.manage', 'users.manage', 'telegram.manage',
            'audit.view', 'settings.manage', 'network.manage', 'network.view',
        ],
        'OPERATOR' => [
            'devices.view', 'devices.lock', 'devices.unlock',
            'devices.location', 'devices.camera', 'devices.command', 'network.view',
        ],
        'VIEWER' => [
            'devices.view', 'audit.view', 'network.view',
        ],
    ];

    /**
     * @var list<string>
     */
    protected array $allPermissions = [
        'devices.view', 'devices.create', 'devices.update', 'devices.delete',
        'devices.lock', 'devices.unlock', 'devices.location', 'devices.camera', 'devices.command',
        'sites.manage', 'teams.manage', 'users.manage', 'telegram.manage',
        'audit.view', 'settings.manage', 'network.manage', 'network.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach ($this->allPermissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
            }

            foreach ($this->roleMatrix as $roleName => $permissions) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'sanctum']);

                // SUPER_ADMIN tidak diberi syncPermissions('*') secara literal — Spatie tidak
                // mendukung wildcard permission. Sebagai gantinya SUPER_ADMIN diberi SEMUA
                // permission yang ada (eksplisit), dan cek otorisasi tambahan via Gate::before
                // di AuthServiceProvider tetap meloloskan role ini untuk permission baru di
                // masa depan tanpa perlu re-seed (lihat AppServiceProvider::boot()).
                $role->syncPermissions(
                    $permissions === ['*'] ? $this->allPermissions : $permissions
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
