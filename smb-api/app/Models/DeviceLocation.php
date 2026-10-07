<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceLocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'latitude', 'longitude', 'accuracy', 'source',
        'recorded_at', 'received_at', 'requested_by_command_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy' => 'float',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requestedByCommand(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'requested_by_command_id');
    }
}
