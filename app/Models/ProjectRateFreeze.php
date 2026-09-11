<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot inmutable de la tasa de cambio BCV de un proyecto en el momento
 * de un trigger de negocio — ver RateFreezeService, única fuente de
 * escritura de este modelo. No tiene `updated_at`: una vez creada, una fila
 * nunca se edita; una corrección crea una fila nueva que la "supersede".
 */
class ProjectRateFreeze extends Model
{
    public const UPDATED_AT = null;

    public const TRIGGER_CONTRATADO = 'CONTRATADO';
    public const TRIGGER_PAGO_ANTICIPO = 'PAGO_ANTICIPO';
    public const TRIGGER_PAGO_FINIQUITO = 'PAGO_FINIQUITO';

    public const TRIGGERS = [
        self::TRIGGER_CONTRATADO,
        self::TRIGGER_PAGO_ANTICIPO,
        self::TRIGGER_PAGO_FINIQUITO,
    ];

    public const SOURCE_AUTO = 'AUTO';
    public const SOURCE_MANUAL = 'MANUAL';

    protected $fillable = [
        'project_id',
        'trigger',
        'base_currency',
        'frozen_rate',
        'frozen_amount_base',
        'exchange_rate_id',
        'source',
        'reason',
        'frozen_at',
        'frozen_by',
        'superseded_by_id',
    ];

    protected $casts = [
        'frozen_rate' => 'float',
        'frozen_amount_base' => 'float',
        'frozen_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function frozenByUser()
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    public function exchangeRate()
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function supersededBy()
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** Congelaciones vigentes (no reemplazadas por una corrección manual posterior). */
    public function scopeActive($query)
    {
        return $query->whereNull('superseded_by_id');
    }
}
