<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'setting_id',
        'setting_key',
        'old_value',
        'new_value',
        'user_id',
        'user_name_snapshot',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public static function record(AppSetting $setting, ?string $oldValue, ?string $newValue): self
    {
        $user = auth()->user();

        return static::create([
            'setting_id' => $setting->id,
            'setting_key' => $setting->key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'user_id' => $user?->id,
            'user_name_snapshot' => $user?->name,
            'changed_at' => now(),
        ]);
    }
}
