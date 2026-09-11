<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Administración de accesos dinámicos por usuario (vistas + tabs), montado
 * bajo role:SUPERADMIN,ADMIN en routes/api.php. Delega toda la lógica de
 * merge/lectura/escritura a AccessResolver — este controller solo valida y
 * traduce HTTP.
 */
class AccessAdminController extends Controller
{
    public function __construct(private AccessResolver $resolver)
    {
    }

    /** GET /admin/users/{user}/access */
    public function show(User $user)
    {
        return response()->json($this->resolver->catalogForUser($user));
    }

    /** PUT /admin/users/{user}/access */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'viewOverrides' => ['array'],
            'viewOverrides.*.view_key' => ['required', 'string'],
            'viewOverrides.*.allowed' => ['nullable', 'boolean'],
            'tabOverrides' => ['array'],
            'tabOverrides.*.view_key' => ['required', 'string'],
            'tabOverrides.*.tab_key' => ['required', 'string'],
            'tabOverrides.*.allowed' => ['nullable', 'boolean'],
        ]);

        $tabOverrides = $data['tabOverrides'] ?? [];

        $emptyViews = $this->resolver->findViewsLeftWithoutTabs($user, $tabOverrides);
        if ($emptyViews !== []) {
            throw ValidationException::withMessages([
                'tabOverrides' => ['Cada vista debe tener al menos una tab activa. Sin tabs: '.implode(', ', $emptyViews)],
            ]);
        }

        $this->resolver->saveOverrides(
            $user,
            $data['viewOverrides'] ?? [],
            $tabOverrides,
        );

        return response()->json($this->resolver->catalogForUser($user));
    }
}
