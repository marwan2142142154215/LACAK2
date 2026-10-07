<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'telegram_id', 'telegram_username', 'user_id', 'status',
        'step_up_required', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'step_up_required' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->user_id !== null;
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'REVOKED';
    }
}
