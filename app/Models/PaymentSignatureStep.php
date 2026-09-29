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
        'is_required',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'is_active' => 'boolean',
        'is_required' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForType(Builder $query, string $paymentType): Builder
    {
        return $query->where('payment_type', $paymentType)->where('is_active', true)->orderBy('step_order');
    }

    /**
     * Un usuario puede firmar este paso si coincide su rol configurado, o si
     * es exactamente el usuario asignado.
     *
     * SUPERADMIN es la cuenta de soporte/depuración del sistema — nunca
     * autosatisface un paso por coincidencia de ROL (aunque alguien
     * configure, a propósito o por error, un paso con role=SUPERADMIN):
     * quien prueba el circuito completo con esa cuenta debe ver el bloqueo
     * real, igual que lo vería cualquier otro rol, en vez de que cada acción
     * se autofirme en silencio y nunca haya nada que depurar. Sigue
     * pudiendo firmar si se lo asigna explícitamente por `user_id` — una
     * designación deliberada de una persona puntual, no "cualquier
     * superadmin".
     */
    public function canBeSignedBy(User $user): bool
    {
        if ($this->user_id !== null) {
            return (int) $this->user_id === (int) $user->id;
        }

        if ($user->role === 'SUPERADMIN') {
            return false;
        }

        return $this->role !== null && $this->role === $user->role;
    }
}
