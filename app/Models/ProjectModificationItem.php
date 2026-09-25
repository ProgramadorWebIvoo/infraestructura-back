<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectModificationItem extends Model
{
    public const TYPE_INCREASE = 'AUMENTO';
    public const TYPE_DECREASE = 'DISMINUCION';

    protected $fillable = ['request_id', 'project_material_id', 'type', 'quantity', 'unit_price_usd', 'note'];

    protected $casts = ['quantity' => 'float', 'unit_price_usd' => 'float'];

    public function request()
    {
        return $this->belongsTo(ProjectModificationRequest::class, 'request_id');
    }

    public function material()
    {
        return $this->belongsTo(ProjectMaterial::class, 'project_material_id');
    }

    public function signedQuantity(): float
    {
        return $this->type === self::TYPE_DECREASE ? -$this->quantity : $this->quantity;
    }
}
