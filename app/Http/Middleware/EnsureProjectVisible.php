<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\SupplierInvitation;
use Closure;
use Illuminate\Http\Request;

/**
 * Propiedad de proyectos y aislamiento del RESIDENTE (F2-R R2).
 *
 * - INFRAESTRUCTURA solo accede a los proyectos que creó (404, no 403, para no
 *   revelar que existen). Cubre rutas con {project}, `project_id` en la
 *   petición y las imágenes internas de propuestas (token de invitación).
 * - RESIDENTE: lista blanca deny-by-default. Solo sesión, acceso, notificaciones
 *   y push tokens; sus endpoints propios (`/resident/*`) llegan en R3.
 * - Cualquier otro rol pasa sin cambios.
 */
class EnsureProjectVisible
{
    /** Rutas que un RESIDENTE puede usar además de las suyas (`resident/*`). */
    private const RESIDENT_ALLOWED = [
        'api/user',
        'api/auth/*',
        'api/logout',
        'api/push-tokens',
        'api/notifications',
        'api/notifications/*',
        'api/resident/*',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user?->role === 'RESIDENTE' && ! $request->is(...self::RESIDENT_ALLOWED)) {
            return response()->json(['message' => 'Acceso no autorizado.'], 403);
        }

        if ($user?->role === 'INFRAESTRUCTURA' && ! $this->ownsRequestedProjects($request, $user)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return $next($request);
    }

    private function ownsRequestedProjects(Request $request, $user): bool
    {
        $routeProject = $request->route('project');
        if ($routeProject instanceof Project && ! $routeProject->isVisibleTo($user)) {
            return false;
        }

        $projectId = $request->input('project_id');
        if (is_string($projectId) && $projectId !== '') {
            $project = Project::find($projectId);
            if ($project && ! $project->isVisibleTo($user)) {
                return false;
            }
        }

        $token = $request->route('token');
        if ($request->route()?->uri() === 'api/supplier-proposal-images/{token}/{path}' && is_string($token)) {
            $invitation = SupplierInvitation::with('project')->find($token);
            if ($invitation?->project && ! $invitation->project->isVisibleTo($user)) {
                return false;
            }
        }

        return true;
    }
}
