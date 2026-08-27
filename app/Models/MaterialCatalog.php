<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialCatalog extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'material_catalog';

    protected $fillable = [
        'name',
        'unit',
        'estimated_unit_price',
        'is_active',
        'category_id',
        'normalized_specs',
        'is_custom_origin',
    ];

    protected $casts = [
        'estimated_unit_price' => 'float',
        'is_active' => 'boolean',
        'normalized_specs' => 'array',
        'is_custom_origin' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function suppliers()
    {
        return $this->hasMany(CatalogProductSupplier::class, 'catalog_product_id');
    }

    public function priceHistory()
    {
        return $this->hasMany(ProductPriceHistory::class, 'catalog_product_id');
    }
}
