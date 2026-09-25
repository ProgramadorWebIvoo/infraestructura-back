<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResidenteRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_residente_is_a_valid_active_role(): void
    {
        $this->assertContains('RESIDENTE', Roles::valid());
    }

    public function test_residente_only_gets_the_residente_view(): void
    {
        $resident = User::factory()->create(['role' => 'RESIDENTE']);

        $response = $this->actingAs($resident)->getJson('/api/auth/permissions');

        $response->assertOk();
        $this->assertSame(['/residente'], array_values($response->json()));
    }

    public function test_residente_view_is_not_granted_to_infraestructura(): void
    {
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);

        $response = $this->actingAs($infra)->getJson('/api/auth/permissions');

        $response->assertOk();
        $this->assertNotContains('/residente', $response->json());
    }

    public function test_residente_tab_is_defined_for_its_view(): void
    {
        $this->assertTrue(
            DB::table('tab_definitions')->where('view_key', '/residente')->where('tab_key', 'obras')->exists()
        );
    }

    public function test_superadmin_can_create_a_residente_user(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($superadmin)->postJson('/api/users', [
            'name' => 'Ing. Residente',
            'email' => 'residente@test.com',
            'password' => 'Secreta123',
            'password_confirmation' => 'Secreta123',
            'role' => 'RESIDENTE',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'residente@test.com', 'role' => 'RESIDENTE']);
    }
}
