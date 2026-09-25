<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectClosureReportItem extends Model
{
    protected $fillable = [
        'report_id', 'project_material_id', 'name', 'unit',
        'contracted_quantity', 'executed_quantity', 'resident_quantity', 'audit_quantity',
        'unit_price_usd', 'note', 'resident_note', 'audit_note',
    ];

    protected $casts = [
        'contracted_quantity' => 'float',
        'executed_quantity' => 'float',
        'resident_quantity' => 'float',
        'audit_quantity' => 'float',
        'unit_price_usd' => 'float',
    ];

    /** Cantidad que rige el finiquito: la de Auditoría, o la del residente, o la del contratista. */
    public function getFinalQuantityAttribute(): float
    {
        return (float) ($this->audit_quantity ?? $this->resident_quantity ?? $this->executed_quantity);
    }

    public function report()
    {
        return $this->belongsTo(ProjectClosureReport::class, 'report_id');
    }
}
