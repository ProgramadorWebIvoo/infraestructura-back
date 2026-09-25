<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ConfigAuditLog;
use App\Models\Localization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Qué depende de un residente y cómo traspasarlo (F2-R R7a, S7 + D17).
 *
 * Un residente responde por sus ubicaciones registradas (residente en vivo,
 * D11) y por las obras de ubicación personalizada que Auditoría le asignó.
 * Mientras tenga pendientes no se puede desactivar, cambiar de rol ni eliminar;
 * el Traspaso mueve todo a otro residente en una sola acción auditada.
 */
class ResidentAssignmentService
{
    /** @return array{localizations: \Illuminate\Support\Collection, projects: \Illuminate\Support\Collection} */
    public function dependencies(User $resident): array
    {
        $localizations = Localization::where('resident_user_id', $resident->id)
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereHas('projects', fn ($p) => $p->open()))
            ->orderBy('title')
            ->get(['id', 'title', 'city']);

        $projects = Project::open()
            ->where('resident_user_id', $resident->id)
            ->whereNull('localization_id')
            ->orderBy('id')
            ->get(['id', 'title']);

        return compact('localizations', 'projects');
    }

    public function hasDependencies(User $resident): bool
    {
        $deps = $this->dependencies($resident);

        return $deps['localizations']->isNotEmpty() || $deps['projects']->isNotEmpty();
    }

    /**
     * Bloquea (422) desactivar/cambiar de rol a un residente con pendientes;
     * el mensaje lista qué depende de él y ofrece el Traspaso.
     */
    public function assertCanLeaveRole(User $resident): void
    {
        if ($resident->role !== 'RESIDENTE') {
            return;
        }

        $deps = $this->dependencies($resident);
        if ($deps['localizations']->isEmpty() && $deps['projects']->isEmpty()) {
            return;
        }

        $parts = [];
        if ($deps['localizations']->isNotEmpty()) {
            $parts[] = 'ubicaciones: ' . $deps['localizations']->map(fn ($l) => "{$l->title} ({$l->city})")->implode(', ');
        }
        if ($deps['projects']->isNotEmpty()) {
            $parts[] = 'obras personalizadas abiertas: ' . $deps['projects']->map(fn ($p) => "{$p->id}")->implode(', ');
        }

        throw ValidationException::withMessages([
            'user' => ["{$resident->name} es residente de " . implode('; ', $parts) . '. Traspase sus pendientes a otro residente antes de continuar.'],
        ])->status(422);
    }

    /**
     * Traspasa todas las ubicaciones y obras personalizadas abiertas de
     * `$from` a `$to`. Devuelve el conteo movido.
     *
     * @return array{localizations: int, projects: int}
     */
    public function transfer(User $from, User $to, string $reason): array
    {
        if ($from->role !== 'RESIDENTE') {
            throw ValidationException::withMessages(['user' => ['El usuario de origen no es un residente.']])->status(422);
        }
        $this->assertValidResident($to);
        if ($from->is($to)) {
            throw ValidationException::withMessages(['toUserId' => ['El residente destino debe ser distinto al de origen.']])->status(422);
        }

        return DB::transaction(function () use ($from, $to, $reason) {
            $localizations = Localization::where('resident_user_id', $from->id)->get();
            foreach ($localizations as $localization) {
                $affected = $localization->projects()->open()->count();
                $localization->update(['resident_user_id' => $to->id]);
                ConfigAuditLog::recordAdminAction(
                    'localization',
                    'Cambio de residente de ubicacion',
                    null,
                    null,
                    "Ubicación: {$localization->locationLabel()} / de {$from->name} a {$to->name} / Obras afectadas: {$affected} / Motivo: {$reason}",
                );
            }

            $projects = Project::open()->where('resident_user_id', $from->id)->whereNull('localization_id')->get();
            foreach ($projects as $project) {
                $project->update(['resident_user_id' => $to->id]);
                AuditLog::record($project, auth()->user()->role, 'Cambio de residente de obra', "de {$from->name} a {$to->name}.", $reason);
            }

            return ['localizations' => $localizations->count(), 'projects' => $projects->count()];
        });
    }

    /** Un residente asignable es un usuario activo con rol RESIDENTE. */
    public function assertValidResident(User $user): void
    {
        if ($user->role !== 'RESIDENTE' || ! $user->isActive()) {
            throw ValidationException::withMessages(['residentUserId' => ['El residente debe ser un usuario activo con rol RESIDENTE.']])->status(422);
        }
    }
}
