<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProjectProposal extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'replaced_by_id',
        'project_id',
        'contractor_code',
        'contractor_name_snapshot',
        'material_cost',
        'material_items',
        'quote_currency',
        'labor_cost',
        'total_cost',
        'delivery_weeks',
        'duration_value',
        'duration_unit',
        'negotiated_advance_percent',
        'description',
        'origen',
        'fecha_oferta',
        'created_by',
        'precio_anterior',
        'precio_nuevo',
        'diferencia',
        'motivo',
        'motivo_anticipo_excedido',
    ];

    protected $casts = [
        'material_cost' => 'float',
        'material_items' => 'array',
        'labor_cost' => 'float',
        'total_cost' => 'float',
        'delivery_weeks' => 'integer',
        'duration_value' => 'integer',
        'negotiated_advance_percent' => 'float',
        'fecha_oferta' => 'date:Y-m-d',
        'precio_anterior' => 'float',
        'precio_nuevo' => 'float',
        'diferencia' => 'float',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** La propuesta de renegociación que reemplazó a esta (si aplica). */
    public function replacedBy()
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** La propuesta original que esta renegociación reemplazó (si aplica). */
    public function replaces()
    {
        return $this->hasOne(self::class, 'replaced_by_id');
    }

    /**
     * Genera un ID único por timestamp + sufijo random (no requiere lock:
     * a diferencia de Project/Contractor/SupplierMaterialProposal, no es
     * secuencial legible, así que la resolución al milisegundo + 4 chars
     * random ya evita colisiones bajo concurrencia normal).
     */
    public static function nextId(): string
    {
        return 'PROP-' . now()->format('Hisv') . '-' . Str::random(4);
    }

    public function getMaterialItemsEnrichedAttribute(): array
    {
        $items = $this->material_items ?? [];
        if (empty($items)) {
            return $items;
        }

        $priceService = app(\App\Services\PriceEstimationService::class);
        $historicalMonths = config('pricing.price_estimation.historical_months', 6);

        return array_map(function ($item) use ($priceService, $historicalMonths) {
            if (!isset($item['catalog_product_id']) || !$item['catalog_product_id']) {
                return $item;
            }

            $est = $priceService->getEstimatedPrice(
                $item['catalog_product_id'],
                $this->contractor_code,
                $historicalMonths
            );

            if ($est) {
                $item['estimatedPriceUsd'] = $est->value;
                $item['estimatedPriceSource'] = $est->source;

                $unitPriceUsd = $item['unit_price_usd'] ?? $item['unitPrice'] ?? 0;
                if ($est->value > 0) {
                    $item['variationPercent'] = (($unitPriceUsd - $est->value) / $est->value) * 100;

                    $threshold = 5;
                    if ($item['variationPercent'] > $threshold) {
                        $item['variationDirection'] = 'increase';
                    } elseif ($item['variationPercent'] < -$threshold) {
                        $item['variationDirection'] = 'decrease';
                    } else {
                        $item['variationDirection'] = 'stable';
                    }
                }
            }

            return $item;
        }, $items);
    }
}
