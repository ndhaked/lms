<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    public const PROVIDERS = ['expo', 'fcm'];

    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = ['token', 'provider', 'platform', 'device_name', 'app_version', 'access_token_id', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
