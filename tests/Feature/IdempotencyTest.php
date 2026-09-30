<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\IdempotencyService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Middleware y servicio de idempotencia (PLAN-Idempotencia). Usa rutas de prueba
 * (`api/_idem/*`) con un contador de ejecuciones: lo que se verifica es cuántas veces
 * corrió realmente el "controller", no solo el status de la respuesta.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public int $hits = 0;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'SUPERADMIN']);
        Sanctum::actingAs($this->user);
        $this->setMode('log');
        $this->registerProbeRoutes();
    }

    private function registerProbeRoutes(): void
    {
        $test = $this;
        $stack = ['api', 'auth:sanctum', 'idempotency'];

        Route::middleware($stack)->group(function () use ($test) {
            Route::post('/api/_idem/ok', function () use ($test) {
                $test->hits++;

                return response()->json(['data' => ['n' => $test->hits]], 201)
                    ->header('X-Refresh-Token', "token-{$test->hits}");
            });
            Route::post('/api/_idem/other', function () use ($test) {
                $test->hits++;

                return response()->json(['data' => ['n' => $test->hits]]);
            });
            Route::delete('/api/_idem/gone', function () use ($test) {
                $test->hits++;

                return response()->noContent();
            });
            Route::post('/api/_idem/fail500', function () use ($test) {
                $test->hits++;

                return response()->json(['message' => 'boom'], 500);
            });
            Route::post('/api/_idem/fail422', function () use ($test) {
                $test->hits++;

                return response()->json(['message' => 'invalid'], 422);
            });
            Route::post('/api/_idem/throws', function () use ($test) {
                $test->hits++;

                throw new \RuntimeException('boom');
            });
            Route::post('/api/_idem/text', function () use ($test) {
                $test->hits++;

                return response('plain text', 200);
            });
            Route::post('/api/_idem/big', function () use ($test) {
                $test->hits++;

                return response()->json(['data' => str_repeat('x', 500)], 201);
            });
            Route::post('/api/_idem/exempt', function () use ($test) {
                $test->hits++;

                return response()->json(['data' => ['n' => $test->hits]]);
            });
            Route::get('/api/_idem/read', function () use ($test) {
                $test->hits++;

                return response()->json(['data' => ['n' => $test->hits]]);
            });
            Route::post('/api/_idem/upload', function () use ($test) {
                $test->hits++;

                return response()->json(['data' => ['n' => $test->hits]], 201);
            });
        });
    }

    private function setMode(string $mode): void
    {
        AppSetting::where('key', IdempotencyService::MODE_SETTING)->update(['value' => $mode]);
        SettingsService::forget();
    }

    private function key(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    // ── Modos ──────────────────────────────────────────────────────────────

    public function test_mode_off_never_stores_keys_and_runs_every_request(): void
    {
        $this->setMode('off');
        $key = $this->key();

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();
        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();

        $this->assertSame(2, $this->hits);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_log_mode_without_key_passes_through_and_logs(): void
    {
        Log::spy();

        $this->postJson('/api/_idem/ok', ['a' => 1])->assertCreated();
        $this->postJson('/api/_idem/ok', ['a' => 1])->assertCreated();

        $this->assertSame(2, $this->hits);
        $this->assertDatabaseCount('idempotency_keys', 0);
        Log::shouldHaveReceived('info')->with('idempotency.key_missing', \Mockery::type('array'))->twice();
    }

    public function test_enforce_mode_without_key_returns_428(): void
    {
        $this->setMode('enforce');

        $this->postJson('/api/_idem/ok', ['a' => 1])
            ->assertStatus(428)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, $this->hits);
    }

    public function test_mode_is_read_from_the_setting_and_unknown_values_fall_back_to_off(): void
    {
        $this->setMode('enforse');
        $key = $this->key();

        $this->postJson('/api/_idem/ok', [], $this->idem($key))->assertCreated();
        $this->postJson('/api/_idem/ok', [], $this->idem($key))->assertCreated();

        $this->assertSame(2, $this->hits);
    }

    public function test_settings_endpoint_rejects_an_invalid_mode_and_accepts_a_valid_one(): void
    {
        $setting = AppSetting::where('key', IdempotencyService::MODE_SETTING)->firstOrFail();

        $this->patchJson("/api/settings/{$setting->id}", ['value' => 'enforse'])->assertStatus(422);
        $this->patchJson("/api/settings/{$setting->id}", ['value' => 'enforce'])->assertOk();

        $this->assertSame('enforce', app(IdempotencyService::class)->mode());
    }

    // ── Replay / conflicto ─────────────────────────────────────────────────

    public function test_same_key_and_payload_replays_the_stored_response_without_running_again(): void
    {
        $key = $this->key();

        $first = $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();
        $second = $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key));

        $this->assertSame(1, $this->hits);
        $second->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson(['data' => ['n' => 1]]);
        $this->assertNull($first->headers->get('Idempotent-Replayed'));

        $row = IdempotencyKey::where('key', $key)->firstOrFail();
        $this->assertSame(IdempotencyKey::STATUS_COMPLETED, $row->status);
        $this->assertSame(201, $row->response_status);
    }

    public function test_payload_key_order_does_not_change_the_fingerprint(): void
    {
        $key = $this->key();

        $this->postJson('/api/_idem/ok', ['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $this->idem($key))->assertCreated();
        $this->postJson('/api/_idem/ok', ['b' => ['y' => 2, 'x' => 1], 'a' => 1], $this->idem($key))
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->hits);
    }

    public function test_same_key_with_a_different_payload_returns_422_and_does_not_run(): void
    {
        $key = $this->key();

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();
        $this->postJson('/api/_idem/ok', ['a' => 2], $this->idem($key))
            ->assertStatus(422)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->hits);
    }

    public function test_same_key_on_a_different_path_returns_422(): void
    {
        $key = $this->key();

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();
        $this->postJson('/api/_idem/other', ['a' => 1], $this->idem($key))
            ->assertStatus(422)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->hits);
    }

    public function test_the_same_key_from_two_users_does_not_collide(): void
    {
        $key = $this->key();
        $other = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();
        Sanctum::actingAs($other);
        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->hits);
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_invalid_key_format_returns_422(): void
    {
        $this->postJson('/api/_idem/ok', [], $this->idem('not-a-uuid'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');

        $this->assertSame(0, $this->hits);
    }

    // ── Petición en vuelo y retoma ─────────────────────────────────────────

    public function test_a_duplicate_while_the_original_is_processing_returns_409_with_retry_after(): void
    {
        $key = $this->key();
        $this->seedRow($key, '/api/_idem/ok', ['a' => 1], IdempotencyKey::STATUS_PROCESSING, now()->addSeconds(30));

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))
            ->assertStatus(409)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');

        $this->assertSame(0, $this->hits);
    }

    public function test_an_expired_processing_marker_is_taken_over_and_completed(): void
    {
        $key = $this->key();
        $this->seedRow($key, '/api/_idem/ok', ['a' => 1], IdempotencyKey::STATUS_PROCESSING, now()->subSeconds(5));

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))->assertCreated();

        $this->assertSame(1, $this->hits);
        $this->assertSame(IdempotencyKey::STATUS_COMPLETED, IdempotencyKey::where('key', $key)->value('status'));
    }

    public function test_only_one_of_two_takeovers_of_an_expired_marker_wins(): void
    {
        $key = $this->key();
        $request = $this->serviceRequest('/api/_idem/ok', ['a' => 1]);
        $this->seedRow($key, '/api/_idem/ok', ['a' => 1], IdempotencyKey::STATUS_PROCESSING, now()->subSeconds(5));
        $service = app(IdempotencyService::class);

        [$first] = $service->begin($request, $key);
        [$second] = $service->begin($request, $key);

        $this->assertSame(IdempotencyService::OUTCOME_PROCEED, $first);
        $this->assertSame(IdempotencyService::OUTCOME_IN_PROGRESS, $second);
    }

    public function test_a_completed_key_past_the_ttl_is_processed_again(): void
    {
        $key = $this->key();
        $row = $this->seedRow($key, '/api/_idem/ok', ['a' => 1], IdempotencyKey::STATUS_COMPLETED, null);
        $row->update(['response_status' => 201, 'response_body' => '{"data":{"n":99}}', 'completed_at' => now()->subHours(73)]);

        $this->postJson('/api/_idem/ok', ['a' => 1], $this->idem($key))
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertExactJson(['data' => ['n' => 1]]);

        $this->assertSame(1, $this->hits);
    }

    // ── Qué se guarda y qué se libera ──────────────────────────────────────

    public function test_error_responses_release_the_key_so_the_retry_runs_again(): void
    {
        foreach (['fail500' => 500, 'fail422' => 422] as $path => $status) {
            $key = $this->key();
            $before = $this->hits;

            $this->postJson("/api/_idem/{$path}", [], $this->idem($key))->assertStatus($status);
            $this->assertDatabaseMissing('idempotency_keys', ['key' => $key]);

            $this->postJson("/api/_idem/{$path}", [], $this->idem($key))->assertStatus($status);
            $this->assertSame($before + 2, $this->hits);
        }
    }

    public function test_an_exception_releases_the_key(): void
    {
        $key = $this->key();

        $this->postJson('/api/_idem/throws', [], $this->idem($key))->assertStatus(500);

        $this->assertDatabaseMissing('idempotency_keys', ['key' => $key]);
    }

    public function test_non_json_success_responses_are_not_stored(): void
    {
        $key = $this->key();

        $this->postJson('/api/_idem/text', [], $this->idem($key))->assertOk();
        $this->postJson('/api/_idem/text', [], $this->idem($key))->assertOk();

        $this->assertSame(2, $this->hits);
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $key]);
    }

    public function test_a_204_is_replayed_as_an_exact_204(): void
    {
        $key = $this->key();

        $this->deleteJson('/api/_idem/gone', [], $this->idem($key))->assertNoContent();
        $this->deleteJson('/api/_idem/gone', [], $this->idem($key))
            ->assertNoContent()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->hits);
    }

    public function test_an_oversized_response_keeps_its_status_and_replays_a_short_body(): void
    {
        config(['idempotency.max_body_bytes' => 100]);
        $key = $this->key();

        $first = $this->postJson('/api/_idem/big', [], $this->idem($key))->assertCreated();
        $this->assertStringContainsString('xxxx', $first->getContent());

        $this->postJson('/api/_idem/big', [], $this->idem($key))
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('code', 'IDEMPOTENCY_RESPONSE_OMITTED');

        $this->assertSame(1, $this->hits);
        $this->assertTrue(IdempotencyKey::where('key', $key)->firstOrFail()->response_omitted);
    }

    public function test_per_request_headers_like_refresh_token_are_never_replayed(): void
    {
        $key = $this->key();

        $first = $this->postJson('/api/_idem/ok', [], $this->idem($key));
        $second = $this->postJson('/api/_idem/ok', [], $this->idem($key));

        $this->assertSame('token-1', $first->headers->get('X-Refresh-Token'));
        $this->assertNull($second->headers->get('X-Refresh-Token'));
    }

    // ── Alcance ────────────────────────────────────────────────────────────

    public function test_exempt_routes_and_reads_never_go_through_idempotency(): void
    {
        config(['idempotency.exempt' => ['api/_idem/exempt']]);
        $key = $this->key();

        $this->postJson('/api/_idem/exempt', [], $this->idem($key));
        $this->postJson('/api/_idem/exempt', [], $this->idem($key));
        $this->getJson('/api/_idem/read', $this->idem($key));
        $this->getJson('/api/_idem/read', $this->idem($key));

        $this->assertSame(4, $this->hits);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_uploads_hash_the_file_content_not_just_name_and_size(): void
    {
        $key = $this->key();
        $headers = $this->idem($key) + ['Accept' => 'application/json'];

        $this->post('/api/_idem/upload', ['file' => UploadedFile::fake()->createWithContent('proof.txt', 'AAAA')], $headers)->assertCreated();

        // Mismo nombre y tamaño, contenido distinto: no es el mismo comprobante.
        $this->post('/api/_idem/upload', ['file' => UploadedFile::fake()->createWithContent('proof.txt', 'BBBB')], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->post('/api/_idem/upload', ['file' => UploadedFile::fake()->createWithContent('proof.txt', 'AAAA')], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->hits);
    }

    public function test_the_middleware_runs_after_project_access_on_real_routes(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/projects/{project}/payments' && in_array('POST', $r->methods(), true)
        );

        $middleware = array_values($route->gatherMiddleware());

        $this->assertContains('idempotency', $middleware);
        $this->assertGreaterThan(array_search('project.access', $middleware, true), array_search('idempotency', $middleware, true));
        $this->assertGreaterThan(array_search('refresh.token', $middleware, true), array_search('idempotency', $middleware, true));
    }

    // ── Limpieza ───────────────────────────────────────────────────────────

    public function test_prune_removes_only_expired_completed_keys_and_orphan_markers(): void
    {
        $oldDone = $this->seedRow($this->key(), '/api/_idem/ok', [], IdempotencyKey::STATUS_COMPLETED, null);
        $oldDone->update(['completed_at' => now()->subHours(73)]);
        $freshDone = $this->seedRow($this->key(), '/api/_idem/ok', [], IdempotencyKey::STATUS_COMPLETED, null);
        $freshDone->update(['completed_at' => now()->subHours(1)]);
        $orphan = $this->seedRow($this->key(), '/api/_idem/ok', [], IdempotencyKey::STATUS_PROCESSING, now()->subDays(2));
        $live = $this->seedRow($this->key(), '/api/_idem/ok', [], IdempotencyKey::STATUS_PROCESSING, now()->addSeconds(30));

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertModelMissing($oldDone);
        $this->assertModelMissing($orphan);
        $this->assertModelExists($freshDone);
        $this->assertModelExists($live);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function serviceRequest(string $path, array $payload): Request
    {
        $request = Request::create($path, 'POST', $payload);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    private function seedRow(string $key, string $path, array $payload, string $status, $lockedUntil): IdempotencyKey
    {
        $request = $this->serviceRequest($path, $payload);

        return IdempotencyKey::create([
            'user_id' => $this->user->id,
            'key' => $key,
            'method' => 'POST',
            'path' => $request->path(),
            'request_hash' => app(IdempotencyService::class)->fingerprint($request),
            'status' => $status,
            'locked_until' => $lockedUntil,
        ]);
    }
}
