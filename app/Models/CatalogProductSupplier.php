<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogProductSupplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_product_id',
        'supplier_code',
        'last_quoted_at',
        'last_quoted_price_usd',
        'quote_count',
    ];

    protected $casts = [
        'last_quoted_at' => 'datetime',
        'last_quoted_price_usd' => 'float',
        'quote_count' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(MaterialCatalog::class, 'catalog_product_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Contractor::class, 'supplier_code', 'code');
    }
}
