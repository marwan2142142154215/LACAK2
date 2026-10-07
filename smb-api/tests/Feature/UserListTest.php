<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function userListToken(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('lets ADMIN list active users with roles', function () {
    $token = userListToken('ADMIN');
    User::factory()->create(['name' => 'Budi', 'is_active' => true]);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/users');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Budi');
    expect($response->json('data.0'))->toHaveKey('roles');
});

it('rejects OPERATOR from listing users', function () {
    $this->withHeader('Authorization', 'Bearer '.userListToken('OPERATOR'))
        ->getJson('/api/v1/users')
        ->assertStatus(403);
});
