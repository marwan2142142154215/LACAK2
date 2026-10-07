<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §6: jalan tiap menit supaya device yang diam >90s terdeteksi DEGRADED/OFFLINE
// tanpa menunggu heartbeat baru. withoutOverlapping supaya tidak dobel kalau run lambat.
Schedule::command('smb:recompute-device-statuses')->everyMinute()->withoutOverlapping();
