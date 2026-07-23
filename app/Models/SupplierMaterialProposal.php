<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierMaterialProposal extends Model
{
    use HasFactory;

    protected $table = 'supplier_material_proposals';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;
    const CREATED_AT = 'submitted_at';

    protected $fillable = [
        'id',
        'invitation_token',
        'project_id',
        'project_title_snapshot',
        'supplier_name',
        'supplier_company',
        'supplier_contact',
        'items',
        'general_notes',
        'estimated_days',
        'duration_unit',
        'submitted_at',
    ];

    protected $casts = [
        'items' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
