<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fuente única de resolución de "acceso efectivo" (vistas SPA + tabs por
 * vista) de un usuario: default de su rol (role_view_access / tab_definitions
 * .default_active) mezclado con sus overrides individuales
 * (user_view_access / user_tab_access). allowed=true agrega, allowed=false
 * revoca — ver docblocks de las migrations 2026_09_11_00000{3,5}.
 *
 * Único consumidor de estas 4 tablas: AuthController (resolución para el
 * usuario autenticado) y AccessAdminController (catálogo + edición de
 * overrides). No duplicar esta lógica de merge en ningún otro lugar.
 */
class AccessResolver
{
    /** @return string[] view_definitions.key que el usuario puede ver, resuelto */
    public function resolveViews(User $user): array
    {
        $roleViews = DB::table('role_view_access')
            ->join('view_definitions', 'view_definitions.id', '=', 'role_view_access.view_definition_id')
            ->where('role_view_access.role', $user->role)
            ->pluck('view_definitions.key');

        $overrides = DB::table('user_view_access')
            ->join('view_definitions', 'view_definitions.id', '=', 'user_view_access.view_definition_id')
            ->where('user_view_access.user_id', $user->id)
            ->pluck('user_view_access.allowed', 'view_definitions.key');

        $effective = [];
        foreach ($roleViews as $key) {
            $effective[$key] = true;
        }
        foreach ($overrides as $key => $allowed) {
            if ($allowed) {
                $effective[$key] = true;
            } else {
                unset($effective[$key]);
            }
        }

        return array_values(array_keys($effective));
    }

    /** @return array<string, string[]> view_key => tab_key[] visibles para el usuario */
    public function resolveTabs(User $user): array
    {
        $tabDefs = DB::table('tab_definitions')->get(['id', 'view_key', 'tab_key', 'default_active']);

        $overrides = DB::table('user_tab_access')
            ->where('user_id', $user->id)
            ->pluck('allowed', 'tab_definition_id');

        $result = [];
        foreach ($tabDefs as $tab) {
            $allowed = $overrides->has($tab->id) ? (bool) $overrides[$tab->id] : (bool) $tab->default_active;
            if (! $allowed) {
                continue;
            }
            $result[$tab->view_key][] = $tab->tab_key;
        }

        return $result;
    }

    /**
     * Catálogo completo para el panel de administración: cada vista con su
     * label, si el rol del usuario objetivo la trae por default, y el override
     * actual (si existe). Igual estructura para tabs, anidadas por vista.
     */
    public function catalogForUser(User $user): array
    {
        $roleViewKeys = DB::table('role_view_access')
            ->join('view_definitions', 'view_definitions.id', '=', 'role_view_access.view_definition_id')
            ->where('role_view_access.role', $user->role)
            ->pluck('view_definitions.key')
            ->all();

        $viewOverrides = DB::table('user_view_access')
            ->join('view_definitions', 'view_definitions.id', '=', 'user_view_access.view_definition_id')
            ->where('user_view_access.user_id', $user->id)
            ->pluck('user_view_access.allowed', 'view_definitions.key');

        $views = DB::table('view_definitions')->orderBy('label')->get(['key', 'label'])
            ->map(fn ($v) => [
                'key' => $v->key,
                'label' => $v->label,
                'defaultFromRole' => in_array($v->key, $roleViewKeys, true),
                'override' => $viewOverrides->has($v->key) ? (bool) $viewOverrides[$v->key] : null,
            ])
            ->values()
            ->all();

        $tabOverrides = DB::table('user_tab_access')
            ->join('tab_definitions', 'tab_definitions.id', '=', 'user_tab_access.tab_definition_id')
            ->where('user_tab_access.user_id', $user->id)
            ->get(['tab_definitions.view_key', 'tab_definitions.tab_key', 'user_tab_access.allowed'])
            ->keyBy(fn ($row) => $row->view_key.'::'.$row->tab_key);

        $tabs = DB::table('tab_definitions')->orderBy('view_key')->orderBy('label')
            ->get(['view_key', 'tab_key', 'label', 'default_active'])
            ->map(function ($t) use ($tabOverrides) {
                $override = $tabOverrides->get($t->view_key.'::'.$t->tab_key);
                return [
                    'viewKey' => $t->view_key,
                    'tabKey' => $t->tab_key,
                    'label' => $t->label,
                    'defaultActive' => (bool) $t->default_active,
                    'override' => $override ? (bool) $override->allowed : null,
                ];
            })
            ->values()
            ->all();

        return ['views' => $views, 'tabs' => $tabs];
    }

    /**
     * Simula el merge de $tabOverrides sobre el estado actual del usuario
     * (sin escribir nada) y devuelve los view_key que quedarían sin ninguna
     * tab activa — una vista así deja al usuario sin nada que ver al entrar
     * (ver useSyncActiveTab en el frontend). Se llama antes de
     * saveOverrides(); el mismo control existe en UserAccessModal.tsx
     * (checkbox deshabilitado) pero no hay que confiar solo en el cliente.
     *
     * @param  array<int, array{view_key: string, tab_key: string, allowed: bool|null}>  $tabOverrides
     * @return string[] view_key de las vistas que quedarían sin tabs
     */
    public function findViewsLeftWithoutTabs(User $user, array $tabOverrides): array
    {
        $tabDefs = DB::table('tab_definitions')->get(['id', 'view_key', 'tab_key', 'default_active']);

        $currentOverrides = DB::table('user_tab_access')
            ->where('user_id', $user->id)
            ->pluck('allowed', 'tab_definition_id');

        $payloadByKey = collect($tabOverrides)->keyBy(fn ($row) => $row['view_key'].'::'.$row['tab_key']);

        $activeCountByView = [];
        foreach ($tabDefs as $tab) {
            $mapKey = $tab->view_key.'::'.$tab->tab_key;
            if ($payloadByKey->has($mapKey)) {
                $allowed = $payloadByKey[$mapKey]['allowed'];
                $effective = $allowed === null ? (bool) $tab->default_active : (bool) $allowed;
            } else {
                $effective = $currentOverrides->has($tab->id) ? (bool) $currentOverrides[$tab->id] : (bool) $tab->default_active;
            }

            $activeCountByView[$tab->view_key] = ($activeCountByView[$tab->view_key] ?? 0) + ($effective ? 1 : 0);
        }

        return array_keys(array_filter($activeCountByView, fn ($count) => $count === 0));
    }

    /**
     * @param  array<int, array{view_key: string, allowed: bool|null}>  $viewOverrides  allowed=null borra el override (vuelve al default del rol)
     * @param  array<int, array{view_key: string, tab_key: string, allowed: bool|null}>  $tabOverrides
     */
    public function saveOverrides(User $user, array $viewOverrides, array $tabOverrides): void
    {
        DB::transaction(function () use ($user, $viewOverrides, $tabOverrides) {
            $viewIds = DB::table('view_definitions')->pluck('id', 'key');
            foreach ($viewOverrides as $row) {
                $viewId = $viewIds[$row['view_key']] ?? null;
                if (! $viewId) {
                    continue;
                }
                if ($row['allowed'] === null) {
                    DB::table('user_view_access')->where('user_id', $user->id)->where('view_definition_id', $viewId)->delete();
                    continue;
                }
                DB::table('user_view_access')->upsert(
                    [[
                        'user_id' => $user->id,
                        'view_definition_id' => $viewId,
                        'allowed' => $row['allowed'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['user_id', 'view_definition_id'],
                    ['allowed', 'updated_at'],
                );
            }

            $tabIds = DB::table('tab_definitions')->get(['id', 'view_key', 'tab_key'])
                ->keyBy(fn ($t) => $t->view_key.'::'.$t->tab_key);
            foreach ($tabOverrides as $row) {
                $tab = $tabIds->get($row['view_key'].'::'.$row['tab_key']);
                if (! $tab) {
                    continue;
                }
                if ($row['allowed'] === null) {
                    DB::table('user_tab_access')->where('user_id', $user->id)->where('tab_definition_id', $tab->id)->delete();
                    continue;
                }
                DB::table('user_tab_access')->upsert(
                    [[
                        'user_id' => $user->id,
                        'tab_definition_id' => $tab->id,
                        'allowed' => $row['allowed'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['user_id', 'tab_definition_id'],
                    ['allowed', 'updated_at'],
                );
            }
        });
    }
}
