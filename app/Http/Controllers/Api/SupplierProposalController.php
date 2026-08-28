<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierProposalImageRequest;
use App\Http\Resources\SupplierProposalResource;
use App\Models\SupplierInvitation;
use App\Models\SupplierMaterialProposal;
use App\Services\CatalogSyncService;
use App\Services\DocumentStorageService;
use App\Services\ProposalLineNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierProposalController extends Controller
{
    use LogsPublicAccess;

    public function __construct(
        private readonly ProposalLineNormalizer $lineNormalizer,
        private readonly CatalogSyncService $catalogSync,
    ) {
    }

    /**
     * Sube la imagen de un ítem ANTES del submit final — el proveedor la
     * carga mientras completa el formulario, recibe un `path` de vuelta, y
     * ese path viaja dentro de `items[].imagePath` al enviar la propuesta
     * completa (store()). Se valida el token igual que store() para que no
     * sea un endpoint de upload arbitrario sin contexto — solo funciona con
     * un enlace de invitación vigente, aunque la propuesta todavía no exista.
     *
     * No se persiste en ninguna tabla acá (la propuesta ni sus líneas
     * existen todavía en este punto del flujo) — el archivo vive en storage
     * bajo el token de invitación; si el proveedor nunca completa el
     * submit, queda huérfano (aceptable: mismo criterio que un adjunto
     * subido y luego abandonado, no hay limpieza automática todavía).
     */
    public function uploadImage(StoreSupplierProposalImageRequest $request, string $token, DocumentStorageService $storage)
    {
        $invitation = SupplierInvitation::find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $file = $request->file('image');
        $directory = "supplier-proposal-images/{$token}";
        $safeName = $storage->sanitizeFilename($file->getClientOriginalName());
        $uniqueName = $storage->uniqueFilename($directory, $safeName);
        $storedPath = $file->storeAs($directory, $uniqueName, 'local');

        return response()->json(['path' => $storedPath], 201);
    }

    /**
     * Sirve una imagen subida vía uploadImage() — nunca directo desde
     * storage/ (el disco 'local' no es públicamente accesible), y valida
     * que el path pertenezca al MISMO token de invitación de la URL, no
     * solo que exista en disco (evita que alguien con un $token válido
     * cualquiera enumere imágenes de otros proveedores).
     */
    public function image(Request $request, string $token, string $path): StreamedResponse
    {
        $invitation = SupplierInvitation::find($token);
        abort_unless($invitation, 404);

        $fullPath = "supplier-proposal-images/{$token}/{$path}";
        abort_unless(Storage::disk('local')->exists($fullPath), 404);

        return new StreamedResponse(function () use ($fullPath) {
            echo Storage::disk('local')->get($fullPath);
        }, 200, [
            'Content-Type' => Storage::disk('local')->mimeType($fullPath) ?: 'application/octet-stream',
        ]);
    }

    /**
     * Sirve la misma imagen para personal interno autenticado (Analistas,
     * Procura) una vez que la propuesta ya fue importada al expediente —
     * mismo par {token}/{path} que uploadImage()/image() (imagePath en
     * material_items YA trae "supplier-proposal-images/{token}/{archivo}",
     * el frontend solo recorta el prefijo fijo antes de armar esta URL). No
     * se restringe por dueño del token, a diferencia de image(): cualquier
     * usuario autenticado puede ver evidencia de cualquier propuesta ya
     * presentada, sin importar de qué invitación vino.
     */
    public function internalImage(string $token, string $path): StreamedResponse
    {
        $fullPath = "supplier-proposal-images/{$token}/{$path}";
        abort_unless(Storage::disk('local')->exists($fullPath), 404);

        return new StreamedResponse(function () use ($fullPath) {
            echo Storage::disk('local')->get($fullPath);
        }, 200, [
            'Content-Type' => Storage::disk('local')->mimeType($fullPath) ?: 'application/octet-stream',
        ]);
    }

    public function store(Request $request, string $token)
    {
        $invitation = SupplierInvitation::with('project')->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        // Sin default: la moneda del pedido la declara el proveedor de forma
        // explícita (obligatoria, sin precarga silenciosa a USD) — si no la
        // manda, falla la validación en vez de asumir una moneda que puede
        // no ser la que el proveedor realmente cotizó.
        if ($request->filled('quoteCurrency')) {
            $request->merge(['quoteCurrency' => strtoupper((string) $request->input('quoteCurrency'))]);
        }

        $data = $request->validate([
            // Moneda única del PEDIDO completo (no por línea) — el proveedor
            // cotiza todo el pedido en una sola moneda, ver
            // ProposalLineNormalizer (hereda esta moneda a cada línea).
            'quoteCurrency'         => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'exists:currencies,code'],
            'estimatedDays'         => ['nullable', 'integer', 'min:1'],
            'durationUnit'          => ['nullable', 'string', 'in:dias,semanas,meses'],
            // Tope fijo (no el configurable de CONFIG APP): el proveedor externo
            // cotiza libremente su condición real de anticipo, sin conocer ni
            // estar limitado por la política interna de la empresa. El 100 es
            // solo una cota de sanidad contra valores absurdos (ej. 500%).
            'advancePercent'        => ['nullable', 'integer', 'min:0', 'max:100'],
            // Costo de mano de obra opcional, a nivel de todo el pedido (no
            // por línea) — análogo a project_proposals.labor_cost.
            'laborCost'             => ['nullable', 'numeric', 'min:0'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.materialName'  => ['required', 'string', 'max:220'],
            'items.*.quantity'      => ['required', 'numeric', 'min:0'],
            'items.*.unit'          => ['required', 'string', 'max:60'],
            'items.*.unitPrice'     => ['required', 'numeric', 'min:0'],
            'items.*.totalPrice'    => ['required', 'numeric', 'min:0'],
            'items.*.notes'         => ['nullable', 'string', 'max:500'],
            // Obligatorios por línea, sin precarga en el frontend — condición
            // y garantía siempre se declaran, sin importar si la línea está
            // vinculada a catálogo o es personalizada. warrantyValue/Unit son
            // el único complemento opcional (la garantía puede ser "de por
            // vida"/"según fabricante" sin un valor+unidad concreto) — ambos
            // van juntos o ninguno, no tiene sentido un valor sin unidad.
            // technicalSpecs sigue nullable acá: su obligatoriedad depende
            // de si la categoría elegida define spec_schema, algo que solo
            // el frontend conoce (ver PropuestaMaterialesPublica).
            'items.*.conditionStatus'    => ['required', 'string', 'in:new,used,refurbished'],
            'items.*.catalogProductId'   => ['nullable', 'integer', 'exists:material_catalog,id'],
            'items.*.technicalSpecs'     => ['nullable', 'array'],
            'items.*.warrantyDescription' => ['required', 'string', 'max:255'],
            'items.*.warrantyValue'      => ['nullable', 'integer', 'min:0', 'max:600', 'required_with:items.*.warrantyUnit'],
            'items.*.warrantyUnit'       => ['nullable', 'string', 'in:dias,semanas,meses', 'required_with:items.*.warrantyValue'],
            // Debe apuntar a un archivo ya subido vía uploadImage() para
            // ESTE mismo token — evita que el proveedor arme el payload a
            // mano referenciando la imagen de otra invitación.
            'items.*.imagePath'          => [
                'nullable', 'string', 'max:500',
                function (string $attribute, mixed $value, \Closure $fail) use ($token) {
                    if ($value !== null && !\Illuminate\Support\Str::startsWith($value, "supplier-proposal-images/{$token}/")) {
                        $fail('La imagen del material no corresponde a esta invitación.');
                    }
                },
            ],
            'generalNotes'          => ['nullable', 'string', 'max:1000'],
        ]);

        $proposal = DB::transaction(function () use ($token, $invitation, $data) {
            $proposal = SupplierMaterialProposal::create([
                'id'                     => SupplierMaterialProposal::nextId(),
                'invitation_token'       => $token,
                'project_id'             => $invitation->project_id,
                'project_title_snapshot' => $invitation->project->title,
                'supplier_name'          => $invitation->supplier_name,
                'supplier_company'       => $invitation->supplier_company,
                'supplier_contact'       => $invitation->supplier_contact,
                'quote_currency'         => $data['quoteCurrency'],
                'items'                  => $data['items'],
                'general_notes'          => $data['generalNotes'] ?? null,
                'estimated_days'         => $data['estimatedDays'] ?? null,
                'duration_unit'          => $data['durationUnit'] ?? null,
                'advance_percent'        => $data['advancePercent'] ?? null,
                'labor_cost'             => $data['laborCost'] ?? null,
            ]);

            // Marcar el enlace como usado (single-use)
            $invitation->update(['used_at' => now()]);

            return $proposal;
        });

        // Normalización de líneas + sincronización de catálogo/histórico:
        // fuera de la transacción principal a propósito. Si falla (ej. tasa
        // de cambio faltante para una moneda), la propuesta YA quedó
        // guardada y el enlace YA quedó marcado como usado — no queremos
        // que un problema de catálogo le devuelva un error al proveedor
        // externo ni lo deje reintentar con el mismo enlace de un solo uso.
        try {
            $lines = $this->lineNormalizer->normalize($proposal);
            $this->catalogSync->sync($proposal, $lines);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('No se pudo sincronizar catálogo/histórico para la propuesta.', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logPublicAccess($request, 'proposal.submit', "Propuesta: {$proposal->id} / Invitación: {$token} / Proveedor: {$invitation->supplier_name}", $invitation->project);

        return response()->json(new SupplierProposalResource($proposal), 201);
    }

    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $query = SupplierMaterialProposal::with('lines')->latest('submitted_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        return response()->json($query->paginate($perPage)->through(fn ($p) => (new SupplierProposalResource($p))->resolve()));
    }
}
