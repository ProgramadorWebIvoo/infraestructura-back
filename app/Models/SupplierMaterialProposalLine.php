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
        'warranty_value',
        'warranty_unit',
        'image_path',
        'line_notes',
        'estimated_price_usd',
        'estimated_price_source',
        'variation_percent',
        'variation_direction',
    ];

    protected $casts = [
        'fx_rate_to_usd' => 'float',
        'unit_price' => 'float',
        'unit_price_usd' => 'float',
        'quantity' => 'float',
        'technical_specs' => 'array',
        'warranty_value' => 'integer',
        'estimated_price_usd' => 'float',
        'variation_percent' => 'float',
    ];

    protected $appends = [
        'estimated_price_display',
        'variation_label',
        'variation_badge_color',
    ];

    /**
     * Replica en PHP el CHECK constraint `chk_smpl_product_identity` (solo
     * activo en MySQL, ver migración de creación de esta tabla) — sin esto,
     * los tests contra SQLite podrían guardar líneas sin identidad de
     * producto (ni catálogo ni nombre personalizado) sin que nada lo impida.
     */
    protected static function booted(): void
    {
        static::saving(function (self $line) {
            if (is_null($line->catalog_product_id) && blank($line->custom_product_name)) {
                throw new \InvalidArgumentException(
                    'La línea debe tener catalog_product_id o custom_product_name.'
                );
            }
        });
    }

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

    public function getEstimatedPriceDisplayAttribute(): string
    {
        if (!$this->estimated_price_usd) {
            return '—';
        }
        return '$' . number_format($this->estimated_price_usd, 2);
    }

    public function getVariationLabelAttribute(): string
    {
        if (!$this->variation_percent) {
            return '—';
        }
        $sign = $this->variation_percent >= 0 ? '+' : '';
        return $sign . number_format($this->variation_percent, 2) . '%';
    }

    public function getVariationBadgeColorAttribute(): string
    {
        if ($this->variation_direction === 'increase') {
            return 'danger';
        }
        if ($this->variation_direction === 'decrease') {
            return 'success';
        }
        return 'neutral';
    }
}
