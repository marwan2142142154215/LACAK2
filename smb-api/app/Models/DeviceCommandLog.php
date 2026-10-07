<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommandLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['command_id', 'from_status', 'to_status', 'note', 'actor'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'command_id');
    }
}
