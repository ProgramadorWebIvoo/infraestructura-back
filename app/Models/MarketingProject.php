<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingProject extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Fuente única de los tipos de pieza válidos — agregar uno nuevo es solo esto + la migración del enum. */
    public const TYPES = ['IMPRESION', 'VINIL', 'PENDON', 'OTRO'];

    public const STATUSES = [
        'BORRADOR' => 'BORRADOR',
        'EN_REVISION' => 'EN_REVISION',
        'APROBADO' => 'APROBADO',
        'RECHAZADO' => 'RECHAZADO',
    ];

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'type',
        'description',
        'location',
        'start_date',
        'end_date',
        'quantity',
        'estimated_cost',
        'priority',
        'status',
        'rejection_reason',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'quantity' => 'integer',
        'estimated_cost' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attachments()
    {
        return $this->hasMany(MarketingProjectAttachment::class)->orderBy('created_at');
    }

    public function scopeStatus($query, ?string $status)
    {
        return $query->when($status, fn ($q) => $q->where('status', $status));
    }

    public function scopeType($query, ?string $type)
    {
        return $query->when($type, fn ($q) => $q->where('type', $type));
    }

    /**
     * Genera el siguiente ID secuencial (MKT-001, MKT-002, ...) — mismo
     * patrón que Project::nextId(). Bloquea la última fila para evitar
     * colisiones bajo concurrencia; el caller debe envolver la creación en
     * una transacción.
     */
    public static function nextId(): string
    {
        $last = static::withTrashed()->select('id')
            ->where('id', 'like', 'MKT-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $number = $last ? ((int) substr($last->id, 4)) + 1 : 1;

        return 'MKT-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
