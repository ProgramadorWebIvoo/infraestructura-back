<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectClosureReport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_OPEN = 'ABIERTO';
    public const STATUS_SENT = 'ENVIADO';
    public const STATUS_RESIDENT_APPROVED = 'APROBADO_RESIDENTE';
    public const STATUS_AUDIT_APPROVED = 'APROBADO_AUDITORIA';
    public const STATUS_REJECTED = 'RECHAZADO';

    protected $fillable = [
        'id', 'project_id', 'contractor_code', 'contractor_email', 'status', 'revision',
        'contractor_notes', 'submitted_at', 'resident_user_id', 'resident_notes', 'resident_verified_at',
        'audit_user_id', 'audit_notes', 'audit_verified_at', 'finiquito_amount',
        'rejection_reason', 'rejected_by_role',
    ];

    protected $casts = [
        'revision' => 'integer',
        'submitted_at' => 'datetime',
        'resident_verified_at' => 'datetime',
        'audit_verified_at' => 'datetime',
        'finiquito_amount' => 'float',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function items()
    {
        return $this->hasMany(ProjectClosureReportItem::class, 'report_id');
    }

    public function photos()
    {
        return $this->hasMany(ProjectClosurePhoto::class, 'report_id');
    }

    /** El contratista solo puede editar/enviar mientras el informe está abierto o rechazado. */
    public function isEditableByContractor(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_REJECTED], true);
    }
}
