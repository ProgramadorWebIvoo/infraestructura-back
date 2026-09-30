<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectPayment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'proposal_id',
        'payment_type',
        'amount',
        'paid_date',
        'notes',
        'currency',
        'bank',
        'reference',
        'comprobante_document_id',
        'payment_order_id',
        'obligation_amount',
        'obligation_currency',
        'payment_mode',
        'paid_currency',
        'paid_amount',
        'applied_rate',
        'applied_rate_source',
        'suggested_rate',
        'covered_amount',
        'difference_amount',
        'difference_reason',
        'contract_rate_freeze_id',
        'payment_rate_freeze_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'obligation_amount' => 'float',
        'paid_amount' => 'float',
        'applied_rate' => 'float',
        'suggested_rate' => 'float',
        'covered_amount' => 'float',
        'difference_amount' => 'float',
        'paid_date' => 'date:Y-m-d',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function proposal()
    {
        return $this->belongsTo(ProjectProposal::class, 'proposal_id');
    }

    public function comprobante()
    {
        return $this->belongsTo(ProjectDocument::class, 'comprobante_document_id')->withTrashed();
    }

    public function paymentOrder()
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    /** Tasa congelada al adjudicar (la de la cotización), si la configuración la aplicó. */
    public function contractRateFreeze()
    {
        return $this->belongsTo(ProjectRateFreeze::class, 'contract_rate_freeze_id');
    }

    /** Tasa congelada en este pago, si la configuración la aplicó. */
    public function paymentRateFreeze()
    {
        return $this->belongsTo(ProjectRateFreeze::class, 'payment_rate_freeze_id');
    }
}
