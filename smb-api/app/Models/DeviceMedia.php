<?php

namespace App\Models;

use App\Services\MediaStorageService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceMedia extends Model
{
    use HasUuids;
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'command_id', 'camera_facing', 'storage_path',
        'mime_type', 'size_bytes', 'sha256_hash', 'captured_at', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'command_id');
    }

    /**
     * §28/§110-114: akses file HANYA via signed URL expiring — storage_path tidak pernah
     * dipublikasikan sebagai URL permanen. Delegasi ke MediaStorageService supaya disk
     * tujuan (lokal default, atau Spaces) bisa ganti lewat .env tanpa ubah model ini.
     */
    public function signedUrl(int $expiresInMinutes = 10): ?string
    {
        return app(MediaStorageService::class)->readUrl($this->storage_path, $expiresInMinutes);
    }
}
