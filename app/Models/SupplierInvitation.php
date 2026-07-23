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

    protected $fillable = [
        'id',
        'project_id',
        'supplier_name',
        'supplier_company',
        'supplier_contact',
        'used_at',
        'replaced_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    /**
     * Verifica si el enlace sigue activo (no usado, no reemplazado).
     */
    public function isValid(): bool
    {
        return is_null($this->used_at) && is_null($this->replaced_by);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
