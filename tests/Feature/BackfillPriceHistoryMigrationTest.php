<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\ProjectProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ejercita 2026_09_24_090100_backfill_quantity_and_project_to_product_price_history
 * directamente (require + up()) contra filas insertadas "a mano" simulando
 * el estado pre-columna (quantity/project_id NULL) — la migración ya corrió
 * en el schema de test (RefreshDatabase la re-ejecuta en cada test), así
 * que este test verifica el comportamiento de la función, no que la
 * migración "corra sin errores".
 */
class BackfillPriceHistoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_24_090100_backfill_quantity_and_project_to_product_price_history.php');
        $migration->up();
    }

    public function test_backfills_project_proposal_quantity_when_product_appears_once(): void
    {
        $project = Project::factory()->create();
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $proposal = ProjectProposal::create([
            'id' => ProjectProposal::nextId(),
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => 100,
            'material_items' => [
                ['catalog_product_id' => $product->id, 'quantity' => 7, 'unit_price' => 10, 'total_price' => 70],
            ],
            'quote_currency' => 'USD',
            'description' => 'test',
            'labor_cost' => 0,
            'total_cost' => 100,
            'origen' => 'MANUAL',
            'fecha_oferta' => now()->toDateString(),
        ]);

        // Simula una fila grabada antes de que quantity/project_id existieran.
        $id = DB::table('product_price_history')->insertGetId([
            'catalog_product_id' => $product->id,
            'supplier_code' => $contractor->code,
            'quantity' => null,
            'project_proposal_id' => $proposal->id,
            'price_usd' => 70,
            'original_currency' => 'USD',
            'original_price' => 70,
            'fx_rate_to_usd' => 1,
            'fx_rate_source' => 'test',
            'quoted_at' => now(),
            'origin' => 'PROJECT_PROPOSAL',
            'project_id' => null,
            'created_at' => now(),
        ]);

        $this->runBackfill();

        $row = DB::table('product_price_history')->where('id', $id)->first();
        $this->assertSame(7.0, (float) $row->quantity);
        $this->assertSame($project->id, $row->project_id);
    }

    public function test_leaves_quantity_null_when_same_product_appears_twice_in_the_proposal(): void
    {
        $project = Project::factory()->create();
        $product = MaterialCatalog::factory()->create();
        $contractor = Contractor::factory()->create();

        $proposal = ProjectProposal::create([
            'id' => ProjectProposal::nextId(),
            'project_id' => $project->id,
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => 100,
            // Mismo catalog_product_id dos veces — ambigüedad real: no hay
            // forma de saber cuál historyRow corresponde a cuál item.
            'material_items' => [
                ['catalog_product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10, 'total_price' => 50],
                ['catalog_product_id' => $product->id, 'quantity' => 9, 'unit_price' => 11, 'total_price' => 99],
            ],
            'quote_currency' => 'USD',
            'description' => 'test',
            'labor_cost' => 0,
            'total_cost' => 149,
            'origen' => 'MANUAL',
            'fecha_oferta' => now()->toDateString(),
        ]);

        $id = DB::table('product_price_history')->insertGetId([
            'catalog_product_id' => $product->id,
            'supplier_code' => $contractor->code,
            'quantity' => null,
            'project_proposal_id' => $proposal->id,
            'price_usd' => 50,
            'original_currency' => 'USD',
            'original_price' => 50,
            'fx_rate_to_usd' => 1,
            'fx_rate_source' => 'test',
            'quoted_at' => now(),
            'origin' => 'PROJECT_PROPOSAL',
            'project_id' => null,
            'created_at' => now(),
        ]);

        $this->runBackfill();

        $row = DB::table('product_price_history')->where('id', $id)->first();
        // project_id sí se puede resolver siempre (directo, sin ambigüedad).
        $this->assertSame($project->id, $row->project_id);
        // quantity queda null: adivinar sería peor que no tener el dato.
        $this->assertNull($row->quantity);
    }
}
