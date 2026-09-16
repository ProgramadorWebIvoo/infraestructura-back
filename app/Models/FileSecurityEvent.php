<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileSecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'context',
        'context_id',
        'original_name',
        'detected_mime',
        'extension',
        'size_bytes',
        'sha256',
        'status',
        'reason',
        'uploaded_by',
        'ip_address',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
