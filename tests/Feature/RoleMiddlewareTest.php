<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
    private User $analista;
    private User $procura;
    private User $finanzas;
    private User $cierre;
    private User $infraestructura;
    private User $noRole;
    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->admin = User::factory()->create(['role' => 'ADMIN']);
        $this->analista = User::factory()->create(['role' => 'ANALISTA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $this->cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $this->infraestructura = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->noRole = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->marketing = User::factory()->create(['role' => 'MARKETING']);
    }



    private function createProject(array $overrides = []): Project
    {
        return Project::factory()->create($overrides);
    }

    public static function routeRoleProvider(): array
    {
        $projectId = '__PROJECT__';

        return [
            'review'               => ['POST', "/api/projects/{$projectId}/review",               ['CIERRE_DE_OBRA']],
            'approve-investment'   => ['POST', "/api/projects/{$projectId}/approve-investment",   ['PROCURA']],
            'add-proposal'         => ['POST', "/api/projects/{$projectId}/proposals",            ['ANALISTA']],
            'submit-comparative'   => ['POST', "/api/projects/{$projectId}/submit-comparative",   ['ANALISTA']],
            'import-supplier'      => ['POST', "/api/projects/{$projectId}/import-supplier-proposals", ['ANALISTA']],
            'reject-proposals'     => ['POST', "/api/projects/{$projectId}/reject-proposals",     ['PROCURA']],
            'select-contractor'    => ['POST', "/api/projects/{$projectId}/select-contractor",    ['PROCURA']],
            'pay'                  => ['POST', "/api/projects/{$projectId}/payments",             ['FINANZAS']],
            'report-finished'      => ['POST', "/api/projects/{$projectId}/report-finished",      ['CIERRE_DE_OBRA']],
            'verify-completion'    => ['POST', "/api/projects/{$projectId}/verify-completion",    ['CIERRE_DE_OBRA']],
        ];
    }

    /** @dataProvider routeRoleProvider */
    public function test_role_middleware_grants_access_to_authorized_role(string $method, string $uri, array $allowedRoles): void
    {
        $project = $this->createProject();
        $uri = str_replace('__PROJECT__', $project->id, $uri);
        $userMap = [
            'CIERRE_DE_OBRA' => $this->cierre,
            'PROCURA'        => $this->procura,
            'ANALISTA'       => $this->analista,
            'FINANZAS'       => $this->finanzas,
        ];

        $user = $userMap[$allowedRoles[0]] ?? $this->procura;

        $response = $this->actingAs($user)->json($method, $uri, [
            // Provide minimal valid data to pass validation
            'notes'                    => 'Test notes',
            'blueprintsCount'          => 1,
            'calculationsAdded'        => true,
            'approvedInvestmentAmount' => 1000,
            'contractorCode'           => null, // will be overridden per endpoint
            'paymentType'              => 'ADVANCE',
            'amount'                   => 500,
            'qualityVerified'          => true,
        ]);

        // If it's not 403, the middleware passed validation
        $this->assertNotEquals(403, $response->getStatusCode(),
            "Route {$uri} should grant access to role {$allowedRoles[0]}");
    }

    /** @dataProvider routeRoleProvider */
    public function test_role_middleware_denies_unauthorized_role(string $method, string $uri): void
    {
        $project = $this->createProject();
        $uri = str_replace('__PROJECT__', $project->id, $uri);

        $response = $this->actingAs($this->infraestructura)->json($method, $uri);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Acceso no autorizado.']);
    }

    /** @dataProvider routeRoleProvider */
    public function test_role_middleware_denies_unauthenticated(string $method, string $uri): void
    {
        $project = $this->createProject();
        $uri = str_replace('__PROJECT__', $project->id, $uri);

        $response = $this->json($method, $uri);
        $response->assertStatus(401);
    }

    /** @dataProvider routeRoleProvider */
    public function test_superadmin_can_access_all(string $method, string $uri): void
    {
        $project = $this->createProject();
        $uri = str_replace('__PROJECT__', $project->id, $uri);

        $response = $this->actingAs($this->superadmin)->json($method, $uri, [
            'notes'                    => 'Test',
            'blueprintsCount'          => 1,
            'calculationsAdded'        => true,
            'approvedInvestmentAmount' => 1000,
            'contractorCode'           => null,
            'paymentType'              => 'ADVANCE',
            'amount'                   => 500,
            'qualityVerified'          => true,
        ]);

        $this->assertNotEquals(403, $response->getStatusCode());
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    /** @dataProvider routeRoleProvider */
    public function test_admin_can_access_all(string $method, string $uri): void
    {
        $project = $this->createProject();
        $uri = str_replace('__PROJECT__', $project->id, $uri);

        $response = $this->actingAs($this->admin)->json($method, $uri, [
            'notes'                    => 'Test',
            'blueprintsCount'          => 1,
            'calculationsAdded'        => true,
            'approvedInvestmentAmount' => 1000,
            'contractorCode'           => null,
            'paymentType'              => 'ADVANCE',
            'amount'                   => 500,
            'qualityVerified'          => true,
        ]);

        $this->assertNotEquals(403, $response->getStatusCode());
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    /**
     * MARKETING tiene acceso parcial a Procura (crear/consultar/adjuntar),
     * pero no debe poder aprobar inversión, rechazar, adjudicar ni disparar
     * evaluación IA — las 4 acciones sensibles del módulo.
     *
     * @dataProvider routeRoleProvider
     */
    public function test_marketing_is_denied_on_sensitive_procura_routes(string $method, string $uri): void
    {
        $project = $this->createProject();
        $uri = str_replace('__PROJECT__', $project->id, $uri);

        $response = $this->actingAs($this->marketing)->json($method, $uri);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Acceso no autorizado.']);
    }

    public function test_marketing_can_create_and_view_projects(): void
    {
        $response = $this->actingAs($this->marketing)->postJson('/api/projects', [
            'title'         => 'Solicitud de Marketing',
            'type'          => 'MANTENIMIENTO',
            'description'   => 'Prueba de acceso parcial',
            'location'      => 'Oficina central',
            'materials'     => [
                ['name' => 'Material X', 'quantity' => 1, 'unit' => 'UND', 'estimatedUnitPrice' => 10, 'condition' => 'NUEVO'],
            ],
        ]);
        $response->assertStatus(201);

        $projectId = $response->json('data.id');

        $response = $this->actingAs($this->marketing)->getJson('/api/projects');
        $response->assertStatus(200);

        $response = $this->actingAs($this->marketing)->getJson("/api/projects/{$projectId}");
        $response->assertStatus(200);
    }

    public function test_ai_evaluate_endpoint_requires_procura(): void
    {
        Project::factory()->create(['id' => 'PRJ-001']);

        // PROCURA should get past middleware (will fail validation later, but not 403)
        $response = $this->actingAs($this->procura)
            ->postJson('/api/ai/evaluate-proposals', ['projectId' => 'PRJ-001']);
        $this->assertNotEquals(403, $response->getStatusCode());

        // INFRAESTRUCTURA should get 403
        $response = $this->actingAs($this->infraestructura)
            ->postJson('/api/ai/evaluate-proposals', ['projectId' => 'PRJ-001']);
        $response->assertStatus(403);

        // MARKETING (acceso parcial a Procura) should get 403 too
        $response = $this->actingAs($this->marketing)
            ->postJson('/api/ai/evaluate-proposals', ['projectId' => 'PRJ-001']);
        $response->assertStatus(403);
    }

    public function test_superadmin_admin_routes_require_elevated_role(): void
    {
        // SUPERADMIN can access users list
        $response = $this->actingAs($this->superadmin)
            ->getJson('/api/users');
        $response->assertStatus(200);

        // ADMIN can access users list
        $response = $this->actingAs($this->admin)
            ->getJson('/api/users');
        $response->assertStatus(200);

        // ANALISTA cannot access users list
        $response = $this->actingAs($this->analista)
            ->getJson('/api/users');
        $response->assertStatus(403);
    }

    public function test_unauthenticated_public_routes_work_without_token(): void
    {
        // Login is public
        $response = $this->postJson('/api/login', [
            'email'    => 'test@test.com',
            'password' => 'secret',
        ]);
        $response->assertStatus(422); // validations fail, not auth

        // Contractor registration is public
        $response = $this->postJson('/api/contractors', [
            'name'      => 'Test Contractor',
            'specialty' => 'General',
            'email'     => 'test@test.com',
        ]);
        $response->assertStatus(201);
    }
}
