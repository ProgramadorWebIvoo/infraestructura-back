<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOrderSignature extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_order_id',
        'step_id',
        'user_id',
        'role',
        'signed_at',
        'ip',
        'user_agent',
        'document_hash',
        'signature_hash',
        'revoked_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id');
    }

    public function step()
    {
        return $this->belongsTo(PaymentSignatureStep::class, 'step_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
