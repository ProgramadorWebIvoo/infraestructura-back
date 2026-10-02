<?php

namespace App\Services;

use App\Models\MarketingProject;
use App\Models\Project;
use App\Support\StoragePaths;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Carpeta raíz de cada proyecto (`{titulo}_{fecha}_{codigo}`). Se calcula una
 * sola vez, la primera vez que se guarda un archivo, y se persiste: renombrar
 * el proyecto después no mueve ni rompe las rutas ya guardadas, y todos los
 * archivos del mismo proyecto caen siempre en la misma carpeta.
 */
class StorageFolderService
{
    private const MAX_SLUG_LENGTH = 60;

    public function projectFolder(Project $project): string
    {
        return $this->resolve($project, $project->title, $project->created_date, $project->getKey());
    }

    /** Carpeta de una pieza de Marketing, bajo su propia raíz de primer nivel. */
    public function marketingFolder(MarketingProject $marketingProject): string
    {
        return StoragePaths::MARKETING_ROOT . '/' . $this->resolve(
            $marketingProject,
            $marketingProject->title,
            $marketingProject->created_at,
            $marketingProject->getKey(),
        );
    }

    private function resolve($model, ?string $title, ?Carbon $date, string $code): string
    {
        if (!empty($model->storage_folder)) {
            return $model->storage_folder;
        }

        $slug = Str::limit(Str::slug((string) $title), self::MAX_SLUG_LENGTH, '');
        $folder = implode('_', array_filter([
            trim($slug, '-'),
            ($date ?? now())->format('Y-m-d'),
            preg_replace('/[^A-Za-z0-9\-]+/', '-', $code),
        ]));

        $model->newQuery()->whereKey($model->getKey())->update(['storage_folder' => $folder]);
        $model->setAttribute('storage_folder', $folder);
        $model->syncOriginalAttribute('storage_folder');

        return $folder;
    }
}
