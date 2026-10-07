<?php

use App\Models\Site;
use App\Models\SiteNetworkPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function networkPolicyTokenFor(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('lets ADMIN create a network policy for a site', function () {
    $site = Site::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.networkPolicyTokenFor('ADMIN'))
        ->postJson("/api/v1/sites/{$site->id}/network-policies", [
            'network_type' => 'IP',
            'value' => '203.0.113.10',
            'description' => 'Kantor pusat',
        ]);

    $response->assertStatus(201)->assertJsonPath('data.value', '203.0.113.10');
    $this->assertDatabaseCount('site_network_policies', 1);
});

it('rejects OPERATOR from creating a network policy', function () {
    $site = Site::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.networkPolicyTokenFor('OPERATOR'))
        ->postJson("/api/v1/sites/{$site->id}/network-policies", ['network_type' => 'IP', 'value' => '203.0.113.10'])
        ->assertStatus(403);
});

it('rejects an invalid network_type', function () {
    $site = Site::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.networkPolicyTokenFor('ADMIN'))
        ->postJson("/api/v1/sites/{$site->id}/network-policies", ['network_type' => 'NOT_A_TYPE', 'value' => 'x'])
        ->assertStatus(422);
});

it('lets ADMIN list and soft-delete a network policy', function () {
    $site = Site::factory()->create();
    $policy = SiteNetworkPolicy::create(['site_id' => $site->id, 'network_type' => 'IP', 'value' => '203.0.113.10', 'is_active' => true]);

    $token = networkPolicyTokenFor('ADMIN');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/sites/{$site->id}/network-policies")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/sites/{$site->id}/network-policies/{$policy->id}")
        ->assertOk();

    expect(SiteNetworkPolicy::find($policy->id))->toBeNull(); // soft-deleted, default query excludes it
    expect(SiteNetworkPolicy::withTrashed()->find($policy->id))->not->toBeNull();
});

it('returns 404 when the policy does not belong to the given site (anti-IDOR)', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $policy = SiteNetworkPolicy::create(['site_id' => $siteA->id, 'network_type' => 'IP', 'value' => '203.0.113.10', 'is_active' => true]);

    $this->withHeader('Authorization', 'Bearer '.networkPolicyTokenFor('ADMIN'))
        ->deleteJson("/api/v1/sites/{$siteB->id}/network-policies/{$policy->id}")
        ->assertStatus(404);
});
