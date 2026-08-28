<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierProposalImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeInvitation(): SupplierInvitation
    {
        $project = Project::factory()->create();

        return SupplierInvitation::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'supplier_name' => 'Acero del Sur',
            'supplier_contact' => 'contacto@acerodelsur.com',
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_uploads_a_valid_image(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file]);

        $response->assertStatus(201);
        $path = $response->json('path');
        $this->assertStringStartsWith("supplier-proposal-images/{$invitation->id}/", $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_rejects_invalid_invitation_token(): void
    {
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $this->postJson('/api/public/invitations/non-existent/proposal-image', ['image' => $file])
            ->assertStatus(404);
    }

    public function test_rejects_used_invitation_token(): void
    {
        $invitation = SupplierInvitation::factory()->used()->create();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->assertStatus(404);
    }

    public function test_rejects_non_image_file(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.pdf', 500, 'application/pdf');

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->assertStatus(422);
    }

    public function test_rejects_oversized_image(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 6 * 1024, 'image/jpeg'); // 6 MB

        $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->assertStatus(422);
    }

    public function test_serves_uploaded_image(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $path = $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->json('path');
        $filename = basename($path);

        $response = $this->get("/api/public/invitations/{$invitation->id}/proposal-image/{$filename}");
        $response->assertStatus(200);
    }

    public function test_cannot_serve_image_from_a_different_invitation(): void
    {
        $ownerInvitation = $this->makeInvitation();
        $otherInvitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $path = $this->postJson("/api/public/invitations/{$ownerInvitation->id}/proposal-image", ['image' => $file])
            ->json('path');
        $filename = basename($path);

        $this->get("/api/public/invitations/{$otherInvitation->id}/proposal-image/{$filename}")
            ->assertStatus(404);
    }

    public function test_submission_rejects_image_path_belonging_to_a_different_invitation(): void
    {
        $ownerInvitation = $this->makeInvitation();
        $otherInvitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $path = $this->postJson("/api/public/invitations/{$ownerInvitation->id}/proposal-image", ['image' => $file])
            ->json('path');

        $response = $this->postJson("/api/public/invitations/{$otherInvitation->id}/proposal", [
            'quoteCurrency' => 'USD',
            'items' => [[
                'materialName' => 'Cemento',
                'quantity' => 1,
                'unit' => 'saco',
                'unitPrice' => 8,
                'totalPrice' => 8,
                'conditionStatus' => 'new',
                'warrantyDescription' => 'N/A',
                'imagePath' => $path,
            ]],
        ]);

        $response->assertStatus(422);
    }

    public function test_submission_accepts_image_path_belonging_to_the_same_invitation(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');

        $path = $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->json('path');

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'quoteCurrency' => 'USD',
            'items' => [[
                'materialName' => 'Cemento',
                'quantity' => 1,
                'unit' => 'saco',
                'unitPrice' => 8,
                'totalPrice' => 8,
                'conditionStatus' => 'new',
                'warrantyDescription' => 'N/A',
                'imagePath' => $path,
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('supplier_material_proposal_lines', ['image_path' => $path]);
    }

    public function test_authenticated_staff_can_serve_a_proposal_image_regardless_of_token(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');
        $path = $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->json('path');

        $user = User::factory()->create();
        // $path viene con el prefijo "supplier-proposal-images/" ya incluido
        // (ver uploadImage()); la ruta interna solo necesita {token}/{archivo}.
        $relativePath = str_replace('supplier-proposal-images/', '', $path);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->get('/api/supplier-proposal-images/' . $relativePath);

        $response->assertStatus(200);
    }

    public function test_internal_image_endpoint_requires_authentication(): void
    {
        $invitation = $this->makeInvitation();
        $file = UploadedFile::fake()->create('producto.jpg', 500, 'image/jpeg');
        $path = $this->postJson("/api/public/invitations/{$invitation->id}/proposal-image", ['image' => $file])
            ->json('path');
        $relativePath = str_replace('supplier-proposal-images/', '', $path);

        $this->getJson('/api/supplier-proposal-images/' . $relativePath)->assertStatus(401);
    }

    public function test_internal_image_endpoint_rejects_nonexistent_file(): void
    {
        $invitation = $this->makeInvitation();
        $user = User::factory()->create();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->get("/api/supplier-proposal-images/{$invitation->id}/no-such-file.jpg")
            ->assertStatus(404);
    }
}
