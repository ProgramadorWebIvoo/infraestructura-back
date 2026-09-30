<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'Inactive');
    }

    // ── Password Reset ──────────────────────────────────────────────────

    /**
     * Auditado igual que cualquier otra acción de la app (AuditLog::record()
     * con `$project = null`, ver AuditLog::record()) para que quede visible
     * en el historial de auditoría. El correo con el token es un flujo de
     * cuenta, no una alerta de negocio: se envía siempre, sin pasar por
     * CONFIG APP (apagarlo dejaría a los usuarios sin poder recuperar su
     * acceso).
     */
    public function sendPasswordResetNotification($token): void
    {
        AuditLog::record(null, 'SISTEMA', 'Solicitud de restablecimiento de contrasena', "Solicitado para: {$this->email}");

        $this->notify(new \App\Notifications\UserPasswordReset($token, $this->email));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    public function isInactive(): bool
    {
        return $this->status === 'Inactive';
    }
}
