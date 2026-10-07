<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCredential extends Model
{
    use HasUuids;

    public $timestamps = true;

    // §39: credential_hash TIDAK PERNAH diserialisasi/ditampilkan setelah dibuat.
    protected $hidden = ['credential_hash'];

    protected $fillable = ['device_id', 'credential_hash', 'public_token_id', 'issued_at'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
