<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('sanctum.expiration', 1440);
    }

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('secret123'),
            'role'     => 'INFRAESTRUCTURA',
            'status'   => 'Active',
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'user@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'role'],
        ]);
        $this->assertNotNull($response->json('token'));
        $this->assertEquals('INFRAESTRUCTURA', $response->json('user.role'));
    }

    public function test_login_with_invalid_password_returns_422(): void
    {
        User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'user@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_with_nonexistent_email_returns_422(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'ghost@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_with_inactive_user_returns_422(): void
    {
        User::factory()->create([
            'email'    => 'inactive@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Inactive',
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'inactive@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_enforces_max_two_sessions(): void
    {
        $user = User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Active',
        ]);

        // Create 2 existing tokens
        $user->createToken('session-1');
        $user->createToken('session-2');
        $this->assertCount(2, $user->tokens);

        // Third login should succeed and drop oldest token
        $response = $this->postJson('/api/login', [
            'email'    => 'user@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        // Still only 2 tokens max — oldest was dropped
        $this->assertCount(2, $user->tokens()->get());
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);
        $token = $user->createToken('test');

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => 'ANALISTA',
            ],
        ]);
    }

    public function test_me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/user');
        $response->assertStatus(401);
    }

    public function test_permissions_returns_route_matrix_for_all_roles(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $response = $this->getJson('/api/auth/permissions', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'SUPERADMIN', 'ADMIN', 'PRESIDENCIA', 'INFRAESTRUCTURA',
            'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'CATALOGOS',
        ]);
        $this->assertContains('/presidencia', $response->json('PRESIDENCIA'));
        $this->assertNotContains('/presidencia', $response->json('ADMIN'));
    }

    public function test_permissions_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/auth/permissions');
        $response->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $response = $this->postJson('/api/logout', [], [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ]);

        $response->assertStatus(204);
        $this->assertCount(0, $user->tokens);
    }

    public function test_logout_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/logout');
        $response->assertStatus(401);
    }

    public function test_token_works_with_device_name(): void
    {
        User::factory()->create([
            'email'    => 'device@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Active',
        ]);

        $response = $this->postJson('/api/login', [
            'email'       => 'device@test.com',
            'password'    => 'secret123',
            'device_name' => 'mobile-app',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);
    }

    // ── Flujo SPA (cookie httpOnly de sesión, sin token expuesto a JS) ──

    private function frontendCsrfCookies(): array
    {
        $csrf = $this->withHeader('Referer', 'http://localhost:3000/login')
            ->get('/sanctum/csrf-cookie');
        $csrf->assertNoContent();

        return collect($csrf->headers->getCookies())
            ->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])
            ->only([config('session.cookie'), 'XSRF-TOKEN'])
            ->all();
    }

    public function test_web_login_establishes_session_cookie_without_exposing_token(): void
    {
        User::factory()->create([
            'email'    => 'spa@test.com',
            'password' => bcrypt('secret123'),
            'role'     => 'INFRAESTRUCTURA',
            'status'   => 'Active',
        ]);

        $cookies = $this->frontendCsrfCookies();
        $xsrfToken = urldecode($cookies['XSRF-TOKEN']);

        $response = $this->withHeader('Referer', 'http://localhost:3000/login')
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->withCookies($cookies)
            ->postJson('/api/login', [
                'email'    => 'spa@test.com',
                'password' => 'secret123',
            ]);

        $response->assertStatus(200);
        $response->assertJsonMissingPath('token');
        $response->assertJsonStructure(['user' => ['id', 'name', 'email', 'role']]);
        $response->assertCookie(config('session.cookie'));
        $this->assertTrue(
            collect($response->headers->getCookies())
                ->first(fn ($c) => $c->getName() === config('session.cookie'))
                ->isHttpOnly()
        );
    }

    // Nota: Laravel desactiva VerifyCsrfToken automáticamente dentro de
    // `php artisan test` (ver VerifyCsrfToken::runningUnitTests()), por lo
    // que el rechazo 419 sin token CSRF no es observable en un Feature test.
    // La protección la provee el middleware core de Laravel/Sanctum
    // (EnsureFrontendRequestsAreStateful + VerifyCsrfToken); lo que sí se
    // verifica aquí es que el middleware quede correctamente enganchado
    // (sesión creada, /user autenticado por cookie, logout invalida sesión).

    public function test_web_session_authenticates_user_endpoint(): void
    {
        User::factory()->create([
            'email'    => 'spa3@test.com',
            'password' => bcrypt('secret123'),
            'role'     => 'FINANZAS',
            'status'   => 'Active',
        ]);

        $cookies = $this->frontendCsrfCookies();
        $xsrfToken = urldecode($cookies['XSRF-TOKEN']);

        $login = $this->withHeader('Referer', 'http://localhost:3000/login')
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->withCookies($cookies)
            ->postJson('/api/login', ['email' => 'spa3@test.com', 'password' => 'secret123']);

        $sessionCookies = collect($login->headers->getCookies())
            ->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])
            ->only([config('session.cookie')])
            ->all();

        $me = $this->withHeader('Referer', 'http://localhost:3000/login')
            ->withCookies($sessionCookies)
            ->getJson('/api/user');

        $me->assertStatus(200);
        $me->assertJson(['user' => ['email' => 'spa3@test.com', 'role' => 'FINANZAS']]);
    }

    public function test_web_logout_invalidates_session(): void
    {
        User::factory()->create([
            'email'    => 'spa4@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Active',
        ]);

        $cookies = $this->frontendCsrfCookies();
        $xsrfToken = urldecode($cookies['XSRF-TOKEN']);

        $login = $this->withHeader('Referer', 'http://localhost:3000/login')
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->withCookies($cookies)
            ->postJson('/api/login', ['email' => 'spa4@test.com', 'password' => 'secret123']);

        $sessionCookies = collect($login->headers->getCookies())
            ->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])
            ->only([config('session.cookie')])
            ->all();

        $logout = $this->withHeader('Referer', 'http://localhost:3000/login')
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->withCookies(array_merge($sessionCookies, ['XSRF-TOKEN' => $cookies['XSRF-TOKEN']]))
            ->postJson('/api/logout');

        $logout->assertStatus(204);

        // Verificado en el mismo ciclo de app (no una nueva request simulada):
        // el guard 'web' quedó sin usuario autenticado tras logout.
        $this->assertGuest('web');
    }
}
