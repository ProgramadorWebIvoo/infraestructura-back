<?php

namespace App\Services;

use App\Exceptions\FileRejectedException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Support\StoragePaths;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Adjuntar archivos a un proyecto. Lo usan el endpoint de documentos y los
 * procesos que reciben sus adjuntos en la misma petición (crear, reenviar,
 * rechazar, reevaluar): si un archivo falla, el proceso completo falla y no
 * queda nada guardado (ni filas ni archivos en disco).
 *
 * Un grupo es ['type' => string, 'files' => UploadedFile[], 'newVersionOf' => ?int].
 */
class ProjectDocumentService
{
    public function __construct(
        private readonly FileIngestionPipeline $pipeline,
        private readonly StorageFolderService $folders,
    ) {
    }

    /**
     * Pasa la pared de seguridad por TODOS los archivos sin tocar disco ni BD.
     * Se llama antes de abrir la transacción del proceso: un archivo
     * rechazado aborta la petición sin haber cambiado nada, y el evento de
     * rechazo queda registrado (fuera de la transacción, no se revierte).
     *
     * @param array<int, array{type: string, files: array<int, UploadedFile>, newVersionOf?: ?int}> $groups
     * @throws FileRejectedException
     */
    public function preflight(array $groups, ?string $projectId = null): void
    {
        foreach ($groups as $group) {
            foreach ($group['files'] as $file) {
                $this->pipeline->scan($file, 'project_document', $projectId);
            }
        }
    }

    /**
     * Guarda todos los grupos en una sola transacción. Si algo falla, borra
     * los archivos que ya se habían escrito en disco y relanza la excepción
     * para que el llamador (y su transacción) también se reviertan.
     *
     * @param array<int, array{type: string, files: array<int, UploadedFile>, newVersionOf?: ?int}> $groups
     * @return array<int, array{document: ProjectDocument, optimized: bool}>
     */
    public function attachGroups(Project $project, array $groups): array
    {
        $role = auth()->user()->role;
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($project, $groups, $role, &$storedPaths) {
                $saved = [];

                foreach ($groups as $group) {
                    $saved = [...$saved, ...$this->attachGroup($project, $group, $role, $storedPaths)];
                }

                $this->syncCounts($project);

                return $saved;
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk(StoragePaths::disk())->delete($path);
            }
            throw $e;
        }
    }

    public function syncCounts(Project $project): void
    {
        $latestIds = $project->documents()->latestVersionOnly()->pluck('id');

        $project->update([
            'calculations_added' => ProjectDocument::whereIn('id', $latestIds)->where('document_type', 'CALC')->exists(),
            'blueprints_count'   => ProjectDocument::whereIn('id', $latestIds)->where('document_type', 'PLANO')->count(),
        ]);
    }

    /**
     * @param array{type: string, files: array<int, UploadedFile>, newVersionOf?: ?int} $group
     * @param array<int, string> $storedPaths acumula lo escrito en disco para poder limpiarlo si falla
     * @return array<int, array{document: ProjectDocument, optimized: bool}>
     */
    private function attachGroup(Project $project, array $group, string $role, array &$storedPaths): array
    {
        $type = $group['type'];
        $newVersionOfId = $group['newVersionOf'] ?? null;
        $groupId = null;
        $nextVersion = 1;

        if ($newVersionOfId !== null) {
            $original = ProjectDocument::where('id', $newVersionOfId)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $groupId = $original->document_group_id;
            $type = $original->document_type; // la versión no puede cambiar el tipo del documento
            $nextVersion = ProjectDocument::where('document_group_id', $groupId)
                ->lockForUpdate()
                ->max('version_number') + 1;
        }

        $saved = [];

        $directory = $this->folders->projectFolder($project) . '/' . StoragePaths::documentFolder($type);

        foreach ($group['files'] as $file) {
            $ingested = $this->pipeline->ingest($file, $directory, 'project_document', $project->id);
            $storedPaths[] = $ingested->storedPath;

            $doc = $project->documents()->create([
                'document_group_id' => $groupId,
                'version_number' => $nextVersion,
                'document_type' => $type,
                'original_name' => $ingested->originalName,
                'stored_path' => $ingested->storedPath,
                'mime_type' => $ingested->mimeType,
                'size_bytes' => $ingested->sizeBytes,
                'uploaded_by' => auth()->id(),
            ]);

            if ($groupId === null) {
                $doc->update(['document_group_id' => $doc->id]);
            }

            $saved[] = ['document' => $doc, 'optimized' => $ingested->optimized];
        }

        $names = implode(', ', array_map(fn (array $s) => $s['document']->original_name, $saved));
        AuditLog::record(
            $project,
            $role,
            $newVersionOfId !== null ? 'Carga de nueva version de documento' : 'Carga de ' . $this->label($type),
            $newVersionOfId !== null ? "V{$nextVersion}: {$names}" : $names,
        );

        return $saved;
    }

    private function label(string $type): string
    {
        return match ($type) {
            'CALC' => 'hojas de calculo/cubicaciones',
            'PLANO' => 'planos de ingenieria',
            'FOTO' => 'fotografias del sitio de obra',
            'CORRECCION' => 'correcciones de peticion rechazada',
            'REEVALUACION' => 'evidencia de solicitud de reevaluacion',
            'COMPROBANTE_ANTICIPO' => 'comprobante de pago de anticipo',
            'COMPROBANTE_FINIQUITO' => 'comprobante de liquidacion final',
            default => 'documentos',
        };
    }
}
