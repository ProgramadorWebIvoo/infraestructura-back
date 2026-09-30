<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOrder extends Model
{
    public const TYPE_ADVANCE = 'ADVANCE';
    public const TYPE_FINAL = 'FINAL';

    public const STATUS_EN_FIRMA = 'EN_FIRMA';
    public const STATUS_FIRMADA = 'FIRMADA';
    public const STATUS_PAGADA = 'PAGADA';
    public const STATUS_ANULADA = 'ANULADA';

    protected $fillable = [
        'number',
        'project_id',
        'proposal_id',
        'contractor_code',
        'payment_type',
        'amount',
        'amount_base',
        'currency',
        'exchange_rate',
        'snapshot',
        'status',
        'content_hash',
        'current_key',
        'void_reason',
        'elaborated_by',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'amount_base' => 'float',
        'exchange_rate' => 'float',
        'snapshot' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function proposal()
    {
        return $this->belongsTo(ProjectProposal::class, 'proposal_id');
    }

    public function contractor()
    {
        return $this->belongsTo(Contractor::class, 'contractor_code', 'code');
    }

    public function elaboratedBy()
    {
        return $this->belongsTo(User::class, 'elaborated_by');
    }

    public function payment()
    {
        return $this->hasOne(ProjectPayment::class, 'payment_order_id');
    }

    public function signatures()
    {
        return $this->hasMany(PaymentOrderSignature::class);
    }

    public static function currentKeyFor(string $projectId, string $paymentType): string
    {
        return "{$projectId}:{$paymentType}";
    }
}
