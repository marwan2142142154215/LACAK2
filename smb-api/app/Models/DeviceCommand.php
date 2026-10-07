<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceCommand extends Model
{
    use HasUuids;

    public const TYPES = ['LOCK', 'UNLOCK', 'LOCATION_REQUEST', 'CAMERA_REQUEST'];

    // §20: state machine. Transisi valid ditegakkan di CommandStatusTransitioner,
    // bukan hanya didaftarkan di sini.
    public const STATUSES = [
        'PENDING', 'QUEUED', 'SENT', 'DELIVERED', 'RECEIVED',
        'EXECUTING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED',
    ];

    public const TERMINAL_STATUSES = ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'];

    protected $fillable = [
        'device_id', 'command_type', 'payload', 'idempotency_key', 'status',
        'failure_reason', 'created_by_type', 'created_by_id', 'device_session_id',
        'expires_at', 'sent_at', 'delivered_at', 'executed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'executed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeviceCommandLog::class, 'command_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
