<?php

use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function tokenFor(User $user): string
{
    return $user->createToken('pest-test')->plainTextToken;
}

// NOTE: setiap test di bawah HANYA melakukan request HTTP sebagai SATU user. Sanctum's
// RequestGuard men-cache user yang sudah ter-resolve per instance AuthManager yang tetap
// hidup antar sub-request DALAM satu test method (beda dari production). Mencampur user
// A dan user B dalam satu test akan membuat request kedua "melihat" user A yang sudah
// ter-cache — itu bukan bug produksi, murni artefak test. Makanya setiap skenario otorisasi
// dipisah per test (lihat juga catatan serupa di LoginTest::logout test).

it('allows ADMIN to create a site', function () {
    $admin = userWithRole('ADMIN');

    $this->withHeader('Authorization', 'Bearer '.tokenFor($admin))
        ->postJson('/api/v1/sites', ['name' => 'Site A', 'code' => 'site-a'])
        ->assertStatus(201)
        ->assertJsonPath('data.code', 'site-a');
});

it('rejects VIEWER from creating a site (§40 permission enforcement)', function () {
    $viewer = userWithRole('VIEWER');

    $this->withHeader('Authorization', 'Bearer '.tokenFor($viewer))
        ->postJson('/api/v1/sites', ['name' => 'Site B', 'code' => 'site-b'])
        ->assertStatus(403);
});

it('lets SUPER_ADMIN bypass permission checks via Gate::before', function () {
    $superAdmin = userWithRole('SUPER_ADMIN');

    $this->withHeader('Authorization', 'Bearer '.tokenFor($superAdmin))
        ->postJson('/api/v1/sites', ['name' => 'Site C', 'code' => 'site-c'])
        ->assertStatus(201);
});

it('rejects duplicate active site code', function () {
    $admin = userWithRole('ADMIN');
    $headers = ['Authorization' => 'Bearer '.tokenFor($admin)];

    $this->withHeaders($headers)
        ->postJson('/api/v1/sites', ['name' => 'Site D', 'code' => 'dup-code'])
        ->assertStatus(201);

    $this->withHeaders($headers)
        ->postJson('/api/v1/sites', ['name' => 'Site D2', 'code' => 'dup-code'])
        ->assertStatus(422);
});

it('allows reusing a site code after the original is soft-deleted (§35 partial unique index)', function () {
    $admin = userWithRole('ADMIN');
    $headers = ['Authorization' => 'Bearer '.tokenFor($admin)];

    $this->withHeaders($headers)
        ->postJson('/api/v1/sites', ['name' => 'Site D', 'code' => 'dup-code-2'])
        ->assertStatus(201);

    Site::where('code', 'dup-code-2')->first()->delete();

    $this->withHeaders($headers)
        ->postJson('/api/v1/sites', ['name' => 'Site D3', 'code' => 'dup-code-2'])
        ->assertStatus(201);
});

it('enforces unique team code per site but allows the same code on a different site', function () {
    $admin = userWithRole('ADMIN');
    $headers = ['Authorization' => 'Bearer '.tokenFor($admin)];

    $site = Site::create(['name' => 'Site E', 'code' => 'site-e']);
    $otherSite = Site::create(['name' => 'Site F', 'code' => 'site-f']);

    $this->withHeaders($headers)
        ->postJson('/api/v1/teams', ['site_id' => $site->id, 'name' => 'Team A', 'code' => 'team-a'])
        ->assertStatus(201);

    $this->withHeaders($headers)
        ->postJson('/api/v1/teams', ['site_id' => $site->id, 'name' => 'Team A2', 'code' => 'team-a'])
        ->assertStatus(422);

    $this->withHeaders($headers)
        ->postJson('/api/v1/teams', ['site_id' => $otherSite->id, 'name' => 'Team A di Site F', 'code' => 'team-a'])
        ->assertStatus(201);
});

it('rejects OPERATOR from managing teams', function () {
    $operator = userWithRole('OPERATOR');
    $site = Site::create(['name' => 'Site G', 'code' => 'site-g']);

    $this->withHeader('Authorization', 'Bearer '.tokenFor($operator))
        ->postJson('/api/v1/teams', ['site_id' => $site->id, 'name' => 'Team B', 'code' => 'team-b'])
        ->assertStatus(403);
});

it('paginates the site list per §38', function () {
    $admin = userWithRole('ADMIN');
    Site::factory()->count(20)->sequence(fn ($seq) => ['code' => 'site-bulk-'.$seq->index])->create([
        'name' => 'Bulk Site',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.tokenFor($admin))
        ->getJson('/api/v1/sites?per_page=5')
        ->assertOk();

    expect($response->json('meta.per_page'))->toBe(5);
    expect($response->json('meta.total'))->toBe(20);
    expect($response->json('data'))->toHaveCount(5);
});
