<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceHeartbeat extends Model
{
    use HasUuids;

    protected $fillable = [
        'device_id', 'battery_level', 'network_type', 'signal_status',
        'connection_state', 'app_version', 'android_version', 'recorded_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
