<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const VALID_ROLES = [
        'SUPERADMIN', 'ADMIN', 'PRESIDENCIA', 'INFRAESTRUCTURA',
        'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'CATALOGOS',
    ];

    private const VALID_STATUSES = ['Active', 'Inactive'];

    public function roles(Request $request)
    {
        return response()->json(self::VALID_ROLES);
    }

    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $users = User::select('id', 'name', 'email', 'role', 'status', 'created_at')
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
            'role'                  => ['required', Rule::in(self::VALID_ROLES)],
            'status'                => ['sometimes', Rule::in(self::VALID_STATUSES)],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'],
            'status'   => $data['status'] ?? 'Active',
        ]);

        return response()->json(new UserResource($user), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'email'  => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role'   => ['sometimes', Rule::in(self::VALID_ROLES)],
            'status' => ['sometimes', Rule::in(self::VALID_STATUSES)],
        ]);

        if (isset($data['name']))   $user->name  = $data['name'];
        if (isset($data['email']))  $user->email = $data['email'];
        if (isset($data['role']))   $user->role  = $data['role'];
        if (isset($data['status'])) $user->status = $data['status'];

        $user->save();

        return response()->json(new UserResource($user));
    }

    public function toggleStatus(User $user)
    {
        $user->status = $user->isActive() ? 'Inactive' : 'Active';
        $user->save();

        // Revocar tokens si se inactiva
        if ($user->isInactive()) {
            $user->tokens()->delete();
        }

        return response()->json([
            'id'     => $user->id,
            'status' => $user->status,
        ]);
    }

    public function sendResetLink(Request $request, User $user)
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Link de restablecimiento enviado al correo del usuario.']);
        }

        return response()->json(['message' => 'No se pudo enviar el link. Intente de nuevo.'], 500);
    }
}
