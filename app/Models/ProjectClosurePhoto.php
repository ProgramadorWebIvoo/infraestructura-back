<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectClosurePhoto extends Model
{
    public const BY_CONTRACTOR = 'CONTRATISTA';
    public const BY_RESIDENT = 'RESIDENTE';

    protected $fillable = [
        'report_id', 'item_id', 'uploaded_by_type', 'uploaded_by_user_id',
        'original_name', 'stored_path', 'mime_type', 'size_bytes',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public function report()
    {
        return $this->belongsTo(ProjectClosureReport::class, 'report_id');
    }
}
