<?php

namespace Tests\Feature;

use App\Support\StoredFileResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StoredFileResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Storage::disk('local')->put('docs/plano.pdf', 'contenido-del-plano');
    }

    public function test_attachment_streams_with_length_nosniff_and_attachment_disposition(): void
    {
        $response = StoredFileResponse::attachment('docs/plano.pdf', 'Plano final.pdf', 'application/pdf');

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame((string) strlen('contenido-del-plano'), $response->headers->get('Content-Length'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Plano final.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_inline_is_not_forced_download_and_is_sandboxed(): void
    {
        $response = StoredFileResponse::inline('docs/plano.pdf', 'plano.pdf', null);

        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($response->headers->get('Content-Type'));
    }

    public function test_filename_with_quotes_cannot_break_the_header(): void
    {
        $response = StoredFileResponse::inline('docs/plano.pdf', "mal\"nombre\r\nX-Evil: 1.pdf", 'application/pdf');

        $this->assertNull($response->headers->get('X-Evil'));
    }

    public function test_missing_file_aborts_with_404_and_clear_message(): void
    {
        try {
            StoredFileResponse::attachment('docs/no-existe.pdf', 'x.pdf', 'application/pdf');
            $this->fail('Debió abortar con 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame(StoredFileResponse::MISSING_MESSAGE, $e->getMessage());
        }
    }
}
