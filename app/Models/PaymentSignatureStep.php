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
     * Autoriza una firma EXPLÍCITA (el usuario entra y hace clic en
     * "Firmar"): SUPERADMIN puede firmar CUALQUIER paso, sin importar el
     * rol/usuario configurado — bypass total, a propósito (decisión
     * 2026-09-29: "debe poder hacer todo"). Para cualquier otro rol, coincide
     * su rol configurado o ser exactamente el usuario asignado.
     *
     * Este bypass NUNCA debe traducirse en una firma silenciosa: los puntos
     * del circuito que auto-firman como efecto colateral de otra acción
     * (`PaymentSignatureService::trySign()`/`assertCanProceed()`) tratan a
     * SUPERADMIN aparte, exigiéndole pasar por este método explícitamente
     * antes de dejarlo continuar — ver esos métodos para el porqué.
     */
    public function canBeSignedBy(User $user): bool
    {
        if ($user->role === 'SUPERADMIN') {
            return true;
        }

        if ($this->user_id !== null) {
            return (int) $this->user_id === (int) $user->id;
        }

        return $this->role !== null && $this->role === $user->role;
    }
}
