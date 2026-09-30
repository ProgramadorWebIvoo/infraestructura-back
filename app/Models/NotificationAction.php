<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAction extends Model
{
    protected $fillable = ['key', 'label', 'group', 'scope', 'critical', 'is_active', 'app_enabled', 'mail_enabled', 'recipient_type'];

    protected $casts = [
        'critical' => 'boolean',
        'is_active' => 'boolean',
        'app_enabled' => 'boolean',
        'mail_enabled' => 'boolean',
    ];
}
