<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectClosureReportItem extends Model
{
    protected $fillable = [
        'report_id', 'project_material_id', 'name', 'unit',
        'contracted_quantity', 'executed_quantity', 'unit_price_usd', 'note',
    ];

    protected $casts = [
        'contracted_quantity' => 'float',
        'executed_quantity' => 'float',
        'unit_price_usd' => 'float',
    ];

    public function report()
    {
        return $this->belongsTo(ProjectClosureReport::class, 'report_id');
    }
}
