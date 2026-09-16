<?php

namespace App\Console\Commands;

use App\Services\AI\RatingIaBatchService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class RunRatingIaCommand extends Command
{
    protected $signature = 'rating-ia:run';
    protected $description = 'Evalúa en lote la sugerencia de rating IA de todos los proveedores activos';

    public function __construct(private RatingIaBatchService $batchService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔄 Iniciando evaluación batch de RatingIA...');

        $log = $this->batchService->run((bool) SettingsService::get('rating_ia_debug', false));

        if ($log->status === 'failed') {
            $this->error("❌ RatingIA batch falló: {$log->error_message}");
            return self::FAILURE;
        }

        $this->info("✅ RatingIA batch completado: {$log->contractors_evaluated} evaluados, {$log->suggestions_generated} sugerencias, {$log->errors_count} errores.");
        return self::SUCCESS;
    }
}
