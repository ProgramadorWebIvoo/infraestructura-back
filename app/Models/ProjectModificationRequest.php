<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Solicitud de modificación (aumento/disminución de partidas) de una obra en ejecución. */
class ProjectModificationRequest extends Model
{
    public const STATUS_PENDING = 'PENDIENTE';
    public const STATUS_APPROVED = 'APROBADA';
    public const STATUS_REJECTED = 'RECHAZADA';

    protected $fillable = [
        'project_id', 'requested_by_user_id', 'status', 'reason',
        'reviewed_by_user_id', 'reviewed_at', 'review_notes', 'rejection_reason',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function items()
    {
        return $this->hasMany(ProjectModificationItem::class, 'request_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** Una solicitud pendiente o rechazada aún puede editarse y reenviarse. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REJECTED], true);
    }

    /** Impacto neto en USD (aumentos suman, disminuciones restan). */
    public function netAmountUsd(): float
    {
        return round($this->items->sum(fn (ProjectModificationItem $i) => $i->signedQuantity() * (float) $i->unit_price_usd), 2);
    }
}
