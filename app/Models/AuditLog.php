<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'project_id',
        'project_title_snapshot',
        'role',
        'action',
        'logged_at',
        'details',
    ];

    protected $casts = [
        'logged_at' => 'datetime:Y-m-d H:i:s',
    ];
}
