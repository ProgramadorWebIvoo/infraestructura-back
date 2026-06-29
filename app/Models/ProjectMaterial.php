<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectMaterial extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'project_id',
        'material_catalog_id',
        'name',
        'quantity',
        'unit',
        'estimated_unit_price',
    ];

    protected $casts = [
        'quantity' => 'float',
        'estimated_unit_price' => 'float',
    ];
}
