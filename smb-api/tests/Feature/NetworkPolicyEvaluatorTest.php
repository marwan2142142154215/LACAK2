<?php

use App\Models\Device;
use App\Models\Site;
use App\Models\SiteNetworkPolicy;
use App\Models\Team;
use App\Services\NetworkPolicyEvaluator;
use App\Services\NetworkPolicyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function deviceInSite(): Device
{
    $site = Site::factory()->create();
    $team = Team::factory()->create(['site_id' => $site->id]);

    return Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => 'Policy Test Device',
        'status' => 'UNKNOWN',
        'is_active' => true,
    ]);
}

it('allows any IP when the site has no network policy configured (default-open, §131)', function () {
    $device = deviceInSite();
    $evaluator = app(NetworkPolicyEvaluator::class);

    expect($evaluator->evaluate($device, '203.0.113.99'))->toBe(NetworkPolicyStatus::ALLOWED);
});

it('returns UNKNOWN when the observed IP cannot be determined or is malformed', function () {
    $device = deviceInSite();
    $evaluator = app(NetworkPolicyEvaluator::class);

    expect($evaluator->evaluate($device, null))->toBe(NetworkPolicyStatus::UNKNOWN);
    expect($evaluator->evaluate($device, 'not-an-ip'))->toBe(NetworkPolicyStatus::UNKNOWN);
});

it('allows an exact IP match and blocks everything else once a policy exists', function () {
    $device = deviceInSite();
    SiteNetworkPolicy::create([
        'site_id' => $device->site_id,
        'network_type' => 'IP',
        'value' => '203.0.113.10',
        'is_active' => true,
    ]);
    $evaluator = app(NetworkPolicyEvaluator::class);

    expect($evaluator->evaluate($device, '203.0.113.10'))->toBe(NetworkPolicyStatus::ALLOWED);
    expect($evaluator->evaluate($device, '203.0.113.11'))->toBe(NetworkPolicyStatus::BLOCKED);
});

it('allows any IP within an active CIDR range and blocks outside it', function () {
    $device = deviceInSite();
    SiteNetworkPolicy::create([
        'site_id' => $device->site_id,
        'network_type' => 'CIDR',
        'value' => '203.0.113.0/24',
        'is_active' => true,
    ]);
    $evaluator = app(NetworkPolicyEvaluator::class);

    expect($evaluator->evaluate($device, '203.0.113.200'))->toBe(NetworkPolicyStatus::ALLOWED);
    expect($evaluator->evaluate($device, '203.0.114.1'))->toBe(NetworkPolicyStatus::BLOCKED);
});

it('ignores an inactive (soft) policy when evaluating', function () {
    $device = deviceInSite();
    SiteNetworkPolicy::create([
        'site_id' => $device->site_id,
        'network_type' => 'IP',
        'value' => '203.0.113.10',
        'is_active' => false,
    ]);
    $evaluator = app(NetworkPolicyEvaluator::class);

    // §98: tidak ada policy AKTIF -> default-open, bukan BLOCKED karena ada row non-aktif.
    expect($evaluator->evaluate($device, '203.0.113.10'))->toBe(NetworkPolicyStatus::ALLOWED);
});

it('prefers the CF-Connecting-IP header over the raw socket IP (§100 trusted edge, not client-reported)', function () {
    $evaluator = app(NetworkPolicyEvaluator::class);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
    $request->headers->set('CF-Connecting-IP', '198.51.100.5');

    expect($evaluator->resolveObservedIp($request))->toBe('198.51.100.5');
});

it('falls back to the socket IP when CF-Connecting-IP is absent or malformed', function () {
    $evaluator = app(NetworkPolicyEvaluator::class);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.9']);
    $request->headers->set('CF-Connecting-IP', 'not-an-ip');

    expect($evaluator->resolveObservedIp($request))->toBe('198.51.100.9');
});
