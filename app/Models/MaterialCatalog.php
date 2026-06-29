<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialCatalog extends Model
{
    protected $table = 'material_catalog';

    protected $fillable = [
        'name',
        'unit',
        'estimated_unit_price',
        'is_active',
    ];

    protected $casts = [
        'estimated_unit_price' => 'float',
        'is_active' => 'boolean',
    ];
}
