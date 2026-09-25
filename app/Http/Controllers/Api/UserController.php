<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ConfigAuditLog;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\ResidentAssignmentService;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const VALID_STATUSES = ['Active', 'Inactive'];

    public function __construct(private readonly ResidentAssignmentService $residents) {}

    /**
     * ADMIN solo gestiona usuarios RESIDENTE (F2-R R7a); SUPERADMIN todos.
     * 404 para no revelar la existencia de otros usuarios.
     */
    private function authorizeTarget(User $target): void
    {
        abort_if(auth()->user()->role === 'ADMIN' && $target->role !== 'RESIDENTE', 404);
    }

    public function roles(Request $request)
    {
        return response()->json(Roles::valid());
    }

    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $users = User::select('id', 'name', 'email', 'role', 'status', 'created_at')
            ->when($request->user()->role === 'ADMIN', fn ($q) => $q->where('role', 'RESIDENTE'))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'unique:users,email'],
            'password'              => ['required', 'string', 'confirmed', StrongPassword::rule()],
            'role'                  => ['required', Rule::in($request->user()->role === 'ADMIN' ? ['RESIDENTE'] : Roles::valid())],
            'status'                => ['sometimes', Rule::in(self::VALID_STATUSES)],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'],
            'status'   => $data['status'] ?? 'Active',
        ]);

        $auditLog = ConfigAuditLog::recordAdminAction('user', 'Creacion de usuario', null, null, "Usuario: {$user->name} ({$user->email}) / Rol: {$user->role}");

        return response()->json([
            ...(new UserResource($user))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeTarget($user);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'email'  => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role'   => ['sometimes', Rule::in($request->user()->role === 'ADMIN' ? ['RESIDENTE'] : Roles::valid())],
            'status' => ['sometimes', Rule::in(self::VALID_STATUSES)],
        ]);

        $previousRole = $user->role;

        // S7: un residente con ubicaciones u obras pendientes no puede irse (desactivarse o cambiar de rol).
        if ((isset($data['role']) && $data['role'] !== $previousRole)
            || (isset($data['status']) && $data['status'] === 'Inactive' && $user->isActive())) {
            $this->residents->assertCanLeaveRole($user);
        }

        if (isset($data['name']))   $user->name  = $data['name'];
        if (isset($data['email']))  $user->email = $data['email'];
        if (isset($data['role']))   $user->role  = $data['role'];
        if (isset($data['status'])) $user->status = $data['status'];

        $user->save();

        $auditLogs = [
            ConfigAuditLog::recordAdminAction('user', 'Modificacion de usuario', null, null, "Usuario: {$user->name} ({$user->email})"),
        ];

        // Escalación de privilegios — se registra y notifica aparte, no
        // implícito dentro de "Modificacion de usuario", porque su audiencia
        // de notificación es más restringida (ver NotificationCatalog).
        if (isset($data['role']) && $data['role'] !== $previousRole) {
            $details = "Usuario: {$user->name} ({$user->email}) / {$previousRole} → {$user->role}";
            $auditLogs[] = ConfigAuditLog::recordAdminAction('user', 'Cambio de rol de usuario', $previousRole, $user->role, $details);
        }

        return response()->json([
            ...(new UserResource($user))->resolve(),
            // Array (no "auditLog" singular) porque esta acción puede
            // generar 2 entradas en una sola llamada (modificación +
            // escalación de rol) — el frontend inserta cada una en el panel.
            'auditLogs' => array_map(fn ($log) => $log->toApiPayload(), $auditLogs),
        ]);
    }

    public function toggleStatus(User $user)
    {
        $this->authorizeTarget($user);

        if ($user->isActive()) {
            $this->residents->assertCanLeaveRole($user);
        }

        $user->status = $user->isActive() ? 'Inactive' : 'Active';
        $user->save();

        // Revocar tokens si se inactiva
        if ($user->isInactive()) {
            $user->tokens()->delete();
        }

        $details = "Usuario: {$user->name} ({$user->email}) / Estado: {$user->status}";
        $auditLog = ConfigAuditLog::recordAdminAction('user', 'Activacion/desactivacion de usuario', null, null, $details);

        return response()->json([
            'id'     => $user->id,
            'status' => $user->status,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function sendResetLink(Request $request, User $user)
    {
        $this->authorizeTarget($user);

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Link de restablecimiento enviado al correo del usuario.']);
        }

        return response()->json(['message' => 'No se pudo enviar el link. Intente de nuevo.'], 500);
    }

    /** GET /residents — residentes activos asignables (F2-R R7a). */
    public function residents()
    {
        return User::where('role', 'RESIDENTE')->where('status', 'Active')->orderBy('name')->get(['id', 'name']);
    }

    /** POST /residents/{user}/transfer — Traspaso D17: mueve ubicaciones y obras personalizadas abiertas a otro residente. */
    public function transferResident(Request $request, User $user)
    {
        $data = $request->validate([
            'toUserId' => ['required', 'integer', 'exists:users,id'],
            'reason'   => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return response()->json($this->residents->transfer($user, User::findOrFail($data['toUserId']), $data['reason']));
    }
}
