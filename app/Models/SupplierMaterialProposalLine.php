<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierMaterialProposalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_material_proposal_id',
        'catalog_product_id',
        'custom_product_name',
        'condition_status',
        'quote_currency',
        'fx_rate_to_usd',
        'unit_price',
        'unit_price_usd',
        'quantity',
        'unit',
        'technical_specs',
        'warranty_description',
        'warranty_months',
        'image_path',
        'line_notes',
    ];

    protected $casts = [
        'fx_rate_to_usd' => 'float',
        'unit_price' => 'float',
        'unit_price_usd' => 'float',
        'quantity' => 'float',
        'technical_specs' => 'array',
        'warranty_months' => 'integer',
    ];

    public function proposal()
    {
        return $this->belongsTo(SupplierMaterialProposal::class, 'supplier_material_proposal_id');
    }

    public function catalogProduct()
    {
        return $this->belongsTo(MaterialCatalog::class, 'catalog_product_id');
    }

    public function customResolution()
    {
        return $this->hasOne(CustomProductResolution::class, 'supplier_material_proposal_line_id');
    }

    public function priceHistoryEntry()
    {
        return $this->hasOne(ProductPriceHistory::class, 'supplier_material_proposal_line_id');
    }

    public function isCustom(): bool
    {
        return is_null($this->catalog_product_id);
    }
}
