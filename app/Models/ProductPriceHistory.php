<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    use HasFactory;

    protected $table = 'product_price_history';

    const UPDATED_AT = null;

    protected $fillable = [
        'catalog_product_id',
        'supplier_code',
        'supplier_material_proposal_line_id',
        'price_usd',
        'original_currency',
        'original_price',
        'fx_rate_to_usd',
        'fx_rate_source',
        'quoted_at',
    ];

    protected $casts = [
        'price_usd' => 'float',
        'original_price' => 'float',
        'fx_rate_to_usd' => 'float',
        'quoted_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(MaterialCatalog::class, 'catalog_product_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Contractor::class, 'supplier_code', 'code');
    }

    public function proposalLine()
    {
        return $this->belongsTo(SupplierMaterialProposalLine::class, 'supplier_material_proposal_line_id');
    }
}
