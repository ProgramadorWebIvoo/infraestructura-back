<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

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
        'dossier_ai_score',
        'dossier_ai_summary',
        'dossier_ai_alerts',
        'dossier_ai_recommendation',
        'dossier_ai_suggested_amount',
        'dossier_ai_completeness_factors',
        'dossier_ai_provider',
        'dossier_ai_evaluated_at',
        'bid_evaluation_ai_winner_code',
        'bid_evaluation_ai_winner_name',
        'bid_evaluation_ai_confidence_score',
        'bid_evaluation_ai_summary',
        'bid_evaluation_ai_strengths',
        'bid_evaluation_ai_weaknesses',
        'bid_evaluation_ai_risk_factors',
        'bid_evaluation_ai_recommendation',
        'bid_evaluation_ai_provider',
        'bid_evaluation_ai_evaluated_at',
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
        'dossier_ai_score' => 'integer',
        'dossier_ai_alerts' => 'array',
        'dossier_ai_suggested_amount' => 'float',
        'dossier_ai_completeness_factors' => 'array',
        'dossier_ai_evaluated_at' => 'datetime',
        'bid_evaluation_ai_confidence_score' => 'integer',
        'bid_evaluation_ai_strengths' => 'array',
        'bid_evaluation_ai_weaknesses' => 'array',
        'bid_evaluation_ai_risk_factors' => 'array',
        'bid_evaluation_ai_evaluated_at' => 'datetime',
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

    public function documents()
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Relaciones estándar para el detalle completo de un proyecto — usado
     * por prácticamente todos los endpoints de ProjectController (17
     * ocurrencias idénticas antes de esta extracción). Cambiar qué se
     * incluye en el "detalle de proyecto" ahora es un solo punto de edición.
     */
    public static function detailRelations(): array
    {
        return ['materials', 'proposals', 'payments', 'documents' => fn ($q) => $q->latestVersionOnly()];
    }

    /**
     * Genera el siguiente ID secuencial (PRJ-001, PRJ-002, ...). Bloquea la
     * última fila para evitar colisiones bajo concurrencia; el caller debe
     * envolver la creación en una transacción.
     */
    public static function nextId(): string
    {
        $last = static::query()->select('id')
            ->where('id', 'like', 'PRJ-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $number = $last ? ((int) substr($last->id, 4)) + 1 : 1;

        return 'PRJ-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
