<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
     * §28: akses file HANYA via signed URL expiring — storage_path tidak pernah
     * dipublikasikan sebagai URL permanen.
     */
    public function signedUrl(int $expiresInMinutes = 10): ?string
    {
        if (! Storage::disk('spaces')->exists($this->storage_path)) {
            return null;
        }

        try {
            return Storage::disk('spaces')->temporaryUrl($this->storage_path, now()->addMinutes($expiresInMinutes));
        } catch (Throwable $e) {
            // Driver yang dipakai (misal 'local' saat testing via Storage::fake(), atau
            // kalau admin salah konfigurasi disk 'spaces' ke driver non-S3) tidak
            // mendukung temporary URL — jujur melaporkan null, bukan URL permanen yang
            // tidak aman sebagai "solusi" (§28/§66).
            return null;
        }
    }
}
