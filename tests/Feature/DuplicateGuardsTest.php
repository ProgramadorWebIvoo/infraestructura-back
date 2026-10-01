<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\MarketingProject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Validaciones anti-duplicados (422 legible) y sus UNIQUE de respaldo en BD. */
class DuplicateGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_signature_step_rejects_same_type_and_order(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $payload = ['paymentType' => 'ADVANCE', 'stepOrder' => 1, 'role' => 'FINANZAS', 'label' => 'Finanzas'];

        $this->actingAs($admin)->postJson('/api/payment-signature-steps/config', $payload)->assertCreated();

        $this->actingAs($admin)->postJson('/api/payment-signature-steps/config', [...$payload, 'label' => 'Otro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stepOrder');
    }

    public function test_same_order_is_allowed_for_a_different_payment_type(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $base = ['stepOrder' => 1, 'role' => 'FINANZAS', 'label' => 'Finanzas'];

        $this->actingAs($admin)->postJson('/api/payment-signature-steps/config', [...$base, 'paymentType' => 'ADVANCE'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/payment-signature-steps/config', [...$base, 'paymentType' => 'FINAL'])->assertCreated();
    }

    public function test_catalog_category_rejects_sibling_with_same_name_but_allows_other_parent(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $parentA = CatalogCategory::create(['name' => 'Ferretería']);
        $parentB = CatalogCategory::create(['name' => 'Eléctrico']);

        $this->actingAs($admin)->postJson('/api/catalog-categories', ['name' => 'Tornillos', 'parent_id' => $parentA->id])->assertCreated();

        $this->actingAs($admin)->postJson('/api/catalog-categories', ['name' => 'Tornillos', 'parent_id' => $parentA->id])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAs($admin)->postJson('/api/catalog-categories', ['name' => 'Tornillos', 'parent_id' => $parentB->id])->assertCreated();
    }

    public function test_catalog_category_rejects_duplicate_root_name(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        CatalogCategory::create(['name' => 'Cemento']);

        $this->actingAs($admin)->postJson('/api/catalog-categories', ['name' => 'Cemento'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_marketing_project_rejects_same_title_type_and_location(): void
    {
        $user = User::factory()->create(['role' => 'MARKETING']);
        $payload = [
            'title' => 'Pendón sede central',
            'type' => 'PENDON',
            'description' => 'Pendón institucional',
            'location' => 'Sede central',
        ];

        $this->actingAs($user)->postJson('/api/marketing-projects', $payload)->assertCreated();

        $this->actingAs($user)->postJson('/api/marketing-projects', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->assertSame(1, MarketingProject::count());
    }

    public function test_database_unique_blocks_duplicate_document_type_labels(): void
    {
        $row = fn (string $key) => [
            'key' => $key,
            'label' => 'Solvencia',
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 1,
        ];

        DB::table('contractor_document_types')->insert($row('solvencia_a'));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('contractor_document_types')->insert($row('solvencia_b'));
    }
}
