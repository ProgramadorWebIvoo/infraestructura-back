<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'parent_id',
        'spec_schema',
    ];

    protected $casts = [
        'spec_schema' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(CatalogCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(CatalogCategory::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(MaterialCatalog::class, 'category_id');
    }
}
