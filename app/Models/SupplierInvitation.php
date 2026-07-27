<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierInvitation extends Model
{
    use HasFactory;

    protected $table = 'supplier_invitations';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;

    /** Vigencia por defecto de un enlace de invitación nunca usado. */
    public const DEFAULT_VALIDITY_DAYS = 7;

    protected $fillable = [
        'id',
        'project_id',
        'supplier_name',
        'supplier_company',
        'supplier_contact',
        'used_at',
        'replaced_by',
        'expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'used_at'    => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Verifica si el enlace sigue activo (no usado, no reemplazado, no expirado).
     */
    public function isValid(): bool
    {
        return is_null($this->used_at)
            && is_null($this->replaced_by)
            && (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
