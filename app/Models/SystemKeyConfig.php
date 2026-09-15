<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemKeyConfig extends Model
{
    protected $table = 'system_key_configs';

    protected $fillable = [
        'group',
        'data',
        'is_active',
    ];

    protected $casts = [
        'data'      => 'encrypted:array',
        'is_active' => 'boolean',
    ];

    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}
