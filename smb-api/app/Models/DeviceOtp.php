<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceOtp extends Model
{
    use HasUuids;

    public $timestamps = false;

    // §39: otp_hash tidak pernah ditampilkan balik.
    protected $hidden = ['otp_hash'];

    protected $fillable = [
        'device_id', 'otp_hash', 'purpose', 'expires_at', 'max_attempts', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempt_count >= $this->max_attempts;
    }
}
