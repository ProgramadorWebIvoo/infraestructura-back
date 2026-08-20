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
        'project_id',
        'contractor_code',
        'contractor_name_snapshot',
        'material_cost',
        'labor_cost',
        'total_cost',
        'delivery_weeks',
        'negotiated_advance_percent',
        'description',
    ];

    protected $casts = [
        'material_cost' => 'float',
        'labor_cost' => 'float',
        'total_cost' => 'float',
        'delivery_weeks' => 'integer',
        'negotiated_advance_percent' => 'float',
    ];

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
}
