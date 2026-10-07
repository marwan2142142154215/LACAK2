<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramCommandConfirmation extends Model
{
    use HasUuids;

    public $timestamps = false;

    // §30/§39: code_hash tidak pernah ditampilkan balik.
    protected $hidden = ['code_hash'];

    protected $fillable = [
        'telegram_account_id', 'device_id', 'command_type', 'payload',
        'code_hash', 'expires_at', 'max_attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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
