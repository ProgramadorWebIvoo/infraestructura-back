<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierMaterialProposal extends Model
{
    use HasFactory;

    protected $table = 'supplier_material_proposals';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;
    const CREATED_AT = 'submitted_at';

    protected $fillable = [
        'id',
        'invitation_token',
        'project_id',
        'project_title_snapshot',
        'supplier_name',
        'supplier_company',
        'supplier_contact',
        'items',
        'general_notes',
        'estimated_days',
        'duration_unit',
        'advance_percent',
        'submitted_at',
    ];

    protected $casts = [
        'items' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Genera el siguiente ID secuencial (SMP-001, SMP-002, ...). Bloquea la
     * última fila para evitar colisiones bajo concurrencia; el caller debe
     * envolver la creación en una transacción.
     */
    public static function nextId(): string
    {
        $last = static::query()
            ->where('id', 'like', 'SMP-%')
            ->orderByRaw('CAST(SUBSTRING(id, 5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->first();

        $number = $last ? ((int) substr($last->id, 4)) + 1 : 1;

        return 'SMP-' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}
