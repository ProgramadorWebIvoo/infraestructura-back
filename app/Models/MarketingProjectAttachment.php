<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingProjectAttachment extends Model
{
    protected $fillable = [
        'marketing_project_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function marketingProject()
    {
        return $this->belongsTo(MarketingProject::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
