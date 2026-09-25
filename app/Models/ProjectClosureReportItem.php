<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectClosureReportItem extends Model
{
    protected $fillable = [
        'report_id', 'project_material_id', 'name', 'unit',
        'contracted_quantity', 'original_quantity', 'executed_quantity', 'resident_quantity',
        'unit_price_usd', 'note', 'resident_note',
    ];

    protected $casts = [
        'contracted_quantity' => 'float',
        'original_quantity' => 'float',
        'executed_quantity' => 'float',
        'resident_quantity' => 'float',
        'unit_price_usd' => 'float',
    ];

    /** Cantidad que rige el finiquito: la del residente o, sin ella, la del contratista. */
    public function getFinalQuantityAttribute(): float
    {
        return (float) ($this->resident_quantity ?? $this->executed_quantity);
    }

    public function report()
    {
        return $this->belongsTo(ProjectClosureReport::class, 'report_id');
    }
}
