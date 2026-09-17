<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAction extends Model
{
    protected $fillable = ['key', 'label', 'group', 'scope', 'critical', 'is_active'];

    protected $casts = [
        'critical' => 'boolean',
        'is_active' => 'boolean',
    ];
}
