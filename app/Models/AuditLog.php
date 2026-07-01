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
        'user_id',
        'user_name_snapshot',
        'action',
        'logged_at',
        'details',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    protected $casts = [
        'logged_at' => 'datetime:Y-m-d H:i:s',
    ];
}
