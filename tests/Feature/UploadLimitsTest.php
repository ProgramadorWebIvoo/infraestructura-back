<?php

namespace Tests\Feature;

use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_shorthand_understands_php_ini_notation(): void
    {
        $this->assertSame(8 * 1024 ** 2, UploadLimits::parseShorthand('8M'));
        $this->assertSame(2 * 1024 ** 3, UploadLimits::parseShorthand('2g'));
        $this->assertSame(512 * 1024, UploadLimits::parseShorthand('512K'));
        $this->assertSame(1000, UploadLimits::parseShorthand('1000'));
        $this->assertSame(PHP_INT_MAX, UploadLimits::parseShorthand('0'));
        $this->assertSame(PHP_INT_MAX, UploadLimits::parseShorthand('-1'));
        $this->assertSame(PHP_INT_MAX, UploadLimits::parseShorthand(''));
    }

    public function test_human_bytes_formats_for_end_users(): void
    {
        $this->assertSame('40 MB', UploadLimits::humanBytes(40 * 1024 ** 2));
        $this->assertSame('1.5 MB', UploadLimits::humanBytes((int) (1.5 * 1024 ** 2)));
        $this->assertSame('512 KB', UploadLimits::humanBytes(512 * 1024));
        $this->assertSame('sin límite', UploadLimits::humanBytes(PHP_INT_MAX));
    }

    public function test_endpoint_is_public_and_combines_app_settings_with_server_limits(): void
    {
        $response = $this->getJson('/api/public/upload-limits');

        $response->assertOk()->assertJsonStructure([
            'data' => ['postMaxBytes', 'uploadMaxBytes', 'maxFileUploads', 'maxFileBytes', 'maxFileCount'],
        ]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['maxFileBytes']);
        $this->assertGreaterThan(0, $data['maxFileCount']);
        $this->assertLessThanOrEqual($data['maxFileUploads'], $data['maxFileCount']);
        if ($data['uploadMaxBytes'] !== null) {
            $this->assertLessThanOrEqual($data['uploadMaxBytes'], $data['maxFileBytes']);
        }
    }

    public function test_post_too_large_returns_json_413_with_clear_message(): void
    {
        Route::post('/api/_test-post-too-large', fn () => throw new PostTooLargeException());

        $response = $this->postJson('/api/_test-post-too-large');

        $response->assertStatus(413)->assertJsonPath('code', 'PAYLOAD_TOO_LARGE');
        $this->assertStringContainsString('por envío', $response->json('message'));
    }

    public function test_failed_upload_validation_message_is_in_spanish(): void
    {
        $this->assertStringContainsString('No se pudo recibir el archivo', trans('validation.uploaded'));
    }
}
