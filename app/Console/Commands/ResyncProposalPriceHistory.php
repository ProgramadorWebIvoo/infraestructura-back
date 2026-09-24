<?php

namespace App\Console\Commands;

use App\Models\ProjectProposal;
use App\Observers\ProjectProposalObserver;
use App\Support\ProposalMaterialItemsNormalizer;
use Illuminate\Console\Command;

/**
 * Repara la trazabilidad de precios de propuestas de Analistas: enlaza cada
 * ítem con su producto de catálogo (por nombre de material del proyecto) y
 * reconstruye sus filas en product_price_history con PRECIO UNITARIO.
 * Idempotente: puede correrse varias veces sin duplicar filas.
 */
class ResyncProposalPriceHistory extends Command
{
    protected $signature = 'price-history:resync-proposals {--dry-run : Solo reporta, no escribe}';

    protected $description = 'Enlaza ítems de propuestas con el catálogo y reconstruye product_price_history (precio unitario)';

    public function handle(ProjectProposalObserver $observer): int
    {
        $dry = (bool) $this->option('dry-run');
        $linked = 0;
        $resynced = 0;

        ProjectProposal::with('project')
            ->whereNotNull('contractor_code')
            ->whereNotNull('material_items')
            ->chunkById(100, function ($proposals) use ($observer, $dry, &$linked, &$resynced) {
                foreach ($proposals as $proposal) {
                    if (!$proposal->project || empty($proposal->material_items)) {
                        continue;
                    }

                    $items = ProposalMaterialItemsNormalizer::withCatalogIds($proposal->project, $proposal->material_items);
                    if ($items !== $proposal->material_items) {
                        $linked++;
                        if (!$dry) {
                            $proposal->material_items = $items;
                            $proposal->saveQuietly();
                        }
                    }

                    if (!$dry) {
                        $observer->resync($proposal);
                    }
                    $resynced++;
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . "Propuestas con ítems enlazados: {$linked}. Propuestas resincronizadas: {$resynced}.");

        return self::SUCCESS;
    }
}
