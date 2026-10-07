<?php

/**
 * Seed N device + device_credentials untuk load test lokal (PHASE 24, §41).
 * HANYA untuk lingkungan testing/staging — JANGAN jalankan ke database produksi.
 *
 * Usage: php scripts/load-test/seed-devices.php <count>
 * Dijalankan dari dalam folder smb-api (butuh bootstrap Laravel).
 */

require __DIR__.'/../../smb-api/vendor/autoload.php';

$app = require __DIR__.'/../../smb-api/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$count = (int) ($argv[1] ?? 50);

$site = Site::query()->first() ?? Site::create(['name' => 'Load Test Site', 'code' => 'ldt-'.Str::random(6), 'is_active' => true]);
$team = Team::query()->where('site_id', $site->id)->first() ?? Team::create(['site_id' => $site->id, 'name' => 'Load Test Team', 'code' => 'ldt-'.Str::random(6), 'is_active' => true]);

$output = [];
for ($i = 0; $i < $count; $i++) {
    $publicTokenId = (string) Str::uuid();
    $secret = Str::random(48);

    // §18: 'id' SENGAJA tidak ada di $fillable Device (device_id immutable, server-generated
    // via HasUuids) — mass-assignment 'id' di Device::create() diam-diam DIABAIKAN, bukan
    // dipakai. Baca ulang $device->id SETELAH create(), jangan asumsikan UUID yang kita
    // generate sendiri di sini yang benar2 tersimpan (ditemukan lewat percobaan nyata di
    // skrip ini, bukan asumsi).
    $device = Device::create([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'name' => "LoadTest-Device-{$i}",
        'status' => 'UNKNOWN',
        'is_managed' => false,
        'is_active' => true,
        'registered_at' => now(),
    ]);

    DeviceCredential::create([
        'device_id' => $device->id,
        'credential_hash' => Hash::make($secret),
        'public_token_id' => $publicTokenId,
        'issued_at' => now(),
    ]);

    $output[] = ['device_id' => $device->id, 'public_token_id' => $publicTokenId, 'device_secret' => $secret];
}

file_put_contents(__DIR__.'/devices.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Seeded {$count} devices -> ".__DIR__."/devices.json\n";
