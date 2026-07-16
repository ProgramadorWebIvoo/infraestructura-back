<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectProposal extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'project_id',
        'contractor_code',
        'contractor_name_snapshot',
        'material_cost',
        'labor_cost',
        'total_cost',
        'delivery_weeks',
        'negotiated_advance_percent',
        'description',
    ];

    protected $casts = [
        'material_cost' => 'float',
        'labor_cost' => 'float',
        'total_cost' => 'float',
        'delivery_weeks' => 'integer',
        'negotiated_advance_percent' => 'float',
    ];
}
