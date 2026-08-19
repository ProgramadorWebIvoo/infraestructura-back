<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractorRequest;
use App\Http\Requests\UpdateContractorRequest;
use App\Http\Resources\ContractorResource;
use App\Models\Contractor;
use App\Models\ConfigAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractorController extends Controller
{
    use LogsPublicAccess;

    public const CONTRACTOR_STATUSES = ['PENDING_REVIEW', 'ACTIVE', 'INACTIVE'];

    public function index()
    {
        $contractors = Contractor::orderBy('name')->get();

        return response()->json(ContractorResource::collection($contractors));
    }

    public function store(StoreContractorRequest $request)
    {
        $data = $request->validated();

        $data['name'] = strip_tags($data['name']);
        $data['specialty'] = strip_tags($data['specialty']);
        if (isset($data['email'])) $data['email'] = strip_tags($data['email']);
        if (isset($data['phone'])) $data['phone'] = strip_tags($data['phone']);

        $contractor = DB::transaction(function () use ($data) {
            $data['code'] ??= Contractor::nextCode();
            $data['rating'] ??= 4.0;
            $data['registration_source'] = 'INTERNAL';
            $data['status'] = $data['status'] ?? 'ACTIVE';

            return Contractor::create($data);
        });

        $details = "Proveedor: {$contractor->name} / Código: {$contractor->code}";
        $auditLog = ConfigAuditLog::recordAdminAction('contractor', 'Alta de proveedor', null, null, $details);

        return response()->json([
            ...(new ContractorResource($contractor))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function show(Contractor $contractor)
    {
        return response()->json(new ContractorResource($contractor));
    }

    public function update(UpdateContractorRequest $request, Contractor $contractor)
    {
        $data = $request->validated();

        if (isset($data['name']))      $data['name'] = strip_tags($data['name']);
        if (isset($data['specialty']))  $data['specialty'] = strip_tags($data['specialty']);
        if (isset($data['email']))      $data['email'] = strip_tags($data['email']);
        if (isset($data['phone']))      $data['phone'] = strip_tags($data['phone']);
        if (isset($data['rating']))     $data['rating'] = round($data['rating'], 1);

        $contractor->update($data);

        $details = "Proveedor: {$contractor->name} / Código: {$contractor->code}";
        $auditLog = ConfigAuditLog::recordAdminAction('contractor', 'Modificacion de proveedor', null, null, $details);

        return response()->json([
            ...(new ContractorResource($contractor))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function toggleStatus(Contractor $contractor)
    {
        $map = [
            'PENDING_REVIEW' => 'ACTIVE',
            'ACTIVE'         => 'INACTIVE',
            'INACTIVE'       => 'ACTIVE',
        ];

        $previousStatus = $contractor->status;
        $contractor->status = $map[$contractor->status] ?? 'ACTIVE';
        $contractor->save();

        $details = "Proveedor: {$contractor->name} / Código: {$contractor->code} / Estado: {$contractor->status}";
        $auditLog = ConfigAuditLog::recordAdminAction('contractor', 'Activacion/desactivacion de proveedor', $previousStatus, $contractor->status, $details);

        return response()->json([
            'code'   => $contractor->code,
            'status' => $contractor->status,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    /**
     * GET /api/contractors (autenticado) — catálogo resumido de contratistas activos.
     */
    public function activeList()
    {
        return Contractor::where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['code', 'name', 'specialty', 'rating', 'email', 'phone', 'status']);
    }

    /**
     * POST /api/contractors (público) — autoregistro de proveedor desde el portal público.
     */
    public function registerPublic(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'unique:contractors,code'],
            'name' => ['required', 'string', 'max:180'],
            'specialty' => ['required', 'string', 'max:180'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        // Sanitización server-side: eliminar etiquetas HTML/XML de campos de texto
        $data['name'] = strip_tags($data['name']);
        $data['specialty'] = strip_tags($data['specialty']);
        $data['email'] = strip_tags($data['email']);
        if (isset($data['phone'])) $data['phone'] = strip_tags($data['phone']);

        $contractor = DB::transaction(function () use ($data) {
            $data['code'] ??= Contractor::nextCode();
            $data['rating'] ??= 4.0;
            $data['registration_source'] = 'PUBLIC_PORTAL';
            $data['status'] = 'PENDING_REVIEW';

            return Contractor::create($data);
        });

        $this->logPublicAccess($request, 'contractor.register', "Proveedor: {$contractor->name} / Código: {$contractor->code}");

        return response()->json($contractor, 201);
    }

    /**
     * POST /api/contractors/{contractor}/rating (autenticado) — actualiza el rating manualmente.
     */
    public function updateRating(Request $request, Contractor $contractor)
    {
        $data = $request->validate([
            'rating' => ['required', 'numeric', 'min:0', 'max:5'],
        ]);

        $previousRating = $contractor->rating;
        $contractor->update(['rating' => round($data['rating'], 1)]);

        $details = "Proveedor: {$contractor->name} / Código: {$contractor->code} / Rating: {$contractor->rating}";
        ConfigAuditLog::recordAdminAction('contractor', 'Calificacion de proveedor', (string) $previousRating, (string) $contractor->rating, $details);

        return response()->json([
            'code'   => $contractor->code,
            'rating' => $contractor->rating,
        ]);
    }
}
