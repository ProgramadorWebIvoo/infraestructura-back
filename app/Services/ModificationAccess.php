<?php

namespace App\Services;

use App\Models\User;

/** Quién puede solicitar y quién aprobar modificaciones de obra: roles configurables en app_settings. */
class ModificationAccess
{
    private const PRIVILEGED = ['ADMIN', 'SUPERADMIN'];

    public function isPrivileged(User $user): bool
    {
        return in_array($user->role, self::PRIVILEGED, true);
    }

    public function canRequest(User $user): bool
    {
        return $this->isPrivileged($user)
            || in_array($user->role, (array) SettingsService::get('modificaciones_roles_solicitantes', ['INFRAESTRUCTURA']), true);
    }

    public function canReview(User $user): bool
    {
        return $this->isPrivileged($user)
            || in_array($user->role, (array) SettingsService::get('modificaciones_roles_aprobadores', ['AUDITORIA']), true);
    }
}
