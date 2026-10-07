<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Device extends Model
{
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'site_id', 'team_id', 'name', 'status', 'is_managed',
        'android_api_level', 'android_version', 'app_version',
        'manufacturer', 'model', 'capability_report',
        'last_heartbeat_at', 'last_seen_ip', 'registered_at', 'registered_by', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_managed' => 'boolean',
            'is_active' => 'boolean',
            'capability_report' => 'array',
            'last_heartbeat_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logExcept(['capability_report'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
