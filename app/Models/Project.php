<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'type',
        'description',
        'location',
        'created_date',
        'status',
        'estimated_total',
        'cierre_obra_notes',
        'calculations_added',
        'blueprints_count',
        'procura_review_notes',
        'approved_investment_amount',
        'selected_contractor_code',
        'selected_proposal_id',
        'quality_verified',
        'completion_verified_date',
    ];

    protected $casts = [
        'created_date' => 'date:Y-m-d',
        'estimated_total' => 'float',
        'calculations_added' => 'boolean',
        'blueprints_count' => 'integer',
        'approved_investment_amount' => 'float',
        'quality_verified' => 'boolean',
        'completion_verified_date' => 'date:Y-m-d',
    ];

    public function materials()
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    public function proposals()
    {
        return $this->hasMany(ProjectProposal::class);
    }

    public function payments()
    {
        return $this->hasMany(ProjectPayment::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
