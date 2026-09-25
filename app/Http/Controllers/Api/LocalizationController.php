<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocalizationRequest;
use App\Http\Resources\LocalizationResource;
use App\Models\ConfigAuditLog;
use App\Models\Localization;
use App\Models\User;
use App\Services\ResidentAssignmentService;

/** Catálogo de ubicaciones registradas (F2-R R7a, D10–D13). */
class LocalizationController extends Controller
{
    public function __construct(private readonly ResidentAssignmentService $residents) {}

    public function index()
    {
        $items = Localization::with('resident:id,name')->withCount('projects')
            ->orderBy('title')->orderBy('city')->get();

        return LocalizationResource::collection($items);
    }

    /** Solo activas, para el formulario de creación de obras. */
    public function activeList()
    {
        $items = Localization::with('resident:id,name')->where('is_active', true)
            ->orderBy('title')->orderBy('city')->get();

        return LocalizationResource::collection($items);
    }

    public function store(StoreLocalizationRequest $request)
    {
        $data = $request->validated();
        $this->residents->assertValidResident(User::findOrFail($data['residentUserId']));

        $localization = Localization::create([
            ...$this->attributes($data),
            'is_active' => $data['isActive'] ?? true,
        ])->load('resident:id,name');

        $auditLog = ConfigAuditLog::recordAdminAction('localization', 'Alta de ubicacion', null, null, "Ubicación: {$localization->locationLabel()} / Residente: {$localization->resident->name}");

        return response()->json([
            ...(new LocalizationResource($localization))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function update(StoreLocalizationRequest $request, Localization $localization)
    {
        $data = $request->validated();
        $previousResident = $localization->resident;
        $newResidentId = $data['residentUserId'] ?? $localization->resident_user_id;
        $residentChanged = (int) $newResidentId !== (int) $localization->resident_user_id;

        if ($residentChanged && blank($data['reason'] ?? null)) {
            return response()->json([
                'message' => 'El motivo es obligatorio al cambiar el residente.',
                'errors'  => ['reason' => ['El motivo es obligatorio al cambiar el residente.']],
            ], 422);
        }

        $localization->update([
            ...$this->attributes($data),
            ...(isset($data['isActive']) ? ['is_active' => $data['isActive']] : []),
        ]);
        $localization->load('resident:id,name');

        $auditLogs = [ConfigAuditLog::recordAdminAction('localization', 'Modificacion de ubicacion', null, null, "Ubicación: {$localization->locationLabel()}")];

        if ($residentChanged) {
            $affected = $localization->projects()->open()->count();
            $auditLogs[] = ConfigAuditLog::recordAdminAction(
                'localization',
                'Cambio de residente de ubicacion',
                null,
                null,
                "Ubicación: {$localization->locationLabel()} / de {$previousResident?->name} a {$localization->resident->name} / Obras afectadas: {$affected} / Motivo: {$data['reason']}",
            );
        }

        return response()->json([
            ...(new LocalizationResource($localization))->resolve(),
            'auditLogs' => array_map(fn ($log) => $log->toApiPayload(), $auditLogs),
        ]);
    }

    public function toggleStatus(Localization $localization)
    {
        $localization->update(['is_active' => ! $localization->is_active]);

        $auditLog = ConfigAuditLog::recordAdminAction('localization', 'Activacion/desactivacion de ubicacion', null, null, "Ubicación: {$localization->locationLabel()} / Activa: " . ($localization->is_active ? 'sí' : 'no'));

        return response()->json([
            'id'       => $localization->id,
            'isActive' => $localization->is_active,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    /** Con obras solo se desactiva, nunca se borra (D12). */
    public function destroy(Localization $localization)
    {
        if ($localization->projects()->exists()) {
            return response()->json(['message' => 'La ubicación tiene obras asociadas: desactívela en lugar de eliminarla.'], 422);
        }

        $label = $localization->locationLabel();
        $localization->delete();
        $auditLog = ConfigAuditLog::recordAdminAction('localization', 'Baja de ubicacion', null, null, "Ubicación: {$label}");

        return response()->json(['auditLog' => $auditLog->toApiPayload()]);
    }

    private function attributes(array $data): array
    {
        $map = [
            'title' => 'title', 'address' => 'address', 'city' => 'city', 'region' => 'region',
            'type' => 'type', 'notes' => 'notes', 'residentUserId' => 'resident_user_id',
        ];
        $out = [];
        foreach ($map as $in => $column) {
            if (array_key_exists($in, $data)) {
                $out[$column] = is_string($data[$in]) ? strip_tags($data[$in]) : $data[$in];
            }
        }

        return $out;
    }
}
