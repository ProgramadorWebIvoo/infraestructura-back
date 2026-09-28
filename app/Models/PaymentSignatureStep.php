<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentSignatureStep extends Model
{
    protected $fillable = [
        'payment_type',
        'step_order',
        'role',
        'user_id',
        'label',
        'is_active',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForType(Builder $query, string $paymentType): Builder
    {
        return $query->where('payment_type', $paymentType)->where('is_active', true)->orderBy('step_order');
    }

    /** Un usuario puede firmar este paso si coincide su rol configurado, o si es exactamente el usuario asignado. */
    public function canBeSignedBy(User $user): bool
    {
        if ($this->user_id !== null) {
            return (int) $this->user_id === (int) $user->id;
        }

        return $this->role !== null && $this->role === $user->role;
    }
}
