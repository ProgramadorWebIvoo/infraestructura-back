<?php

namespace Tests\Feature;

use App\Exceptions\FileRejectedException;
use App\Models\FileSecurityEvent;
use App\Services\FileIngestionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Pipeline completo de subida: tipo real por contenido, rechazo de código
 * embebido, sanitización, normalización de nombre/extensión y compresión.
 * Los payloads de ataque se arman en runtime (concatenados) porque los
 * antivirus bloquean archivos que los contienen literales.
 */
class FileIngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ing');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pipeline(): FileIngestionPipeline
    {
        return app(FileIngestionPipeline::class);
    }

    private function ingest(string $name, string $contents): \App\Support\IngestedFile
    {
        return $this->pipeline()->ingest($this->upload($name, $contents), 'tests/ingest', 'test_context', 'ctx-1');
    }

    private function jpeg(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < 500; $i++) {
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($image, null, 100);

        return (string) ob_get_clean();
    }

    private function phpTag(): string
    {
        return '<' . '?ph' . 'p';
    }

    private function scriptTag(): string
    {
        return '<scr' . 'ipt>alert(1)</scr' . 'ipt>';
    }

    // ── Tipo real, nombre y extensión ───────────────────────────────

    public function test_stores_canonical_name_extension_and_mime_regardless_of_what_the_client_declared(): void
    {
        $ingested = $this->ingest('Foto de Obra.JPEG', $this->jpeg());

        $this->assertSame('Foto_de_Obra.jpg', $ingested->originalName);
        $this->assertSame('image/jpeg', $ingested->mimeType);
        Storage::disk('local')->assertExists('tests/ingest/Foto_de_Obra.jpg');
    }

    public function test_flattens_inner_dots_so_no_double_extension_survives(): void
    {
        $ingested = $this->ingest('plano.v2.final.jpg', $this->jpeg());

        $this->assertSame('plano_v2_final.jpg', $ingested->originalName);
    }

    public function test_rejects_content_that_does_not_match_the_declared_extension(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('foto.png', $this->jpeg());
    }

    public function test_rejects_executable_disguised_as_image(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('foto.jpg', "MZ\x90\x00" . str_repeat('A', 200));
    }

    public function test_rejects_unknown_extension(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('macros.docm', 'contenido');
    }

    public function test_rejection_is_audited_and_nothing_is_written(): void
    {
        try {
            $this->ingest('foto.jpg', str_repeat("\0", 512));
            $this->fail('Debió rechazarse.');
        } catch (FileRejectedException) {
            // esperado
        }

        $this->assertDatabaseHas('file_security_events', ['context' => 'test_context', 'status' => 'rejected']);
        $this->assertSame([], Storage::disk('local')->allFiles('tests/ingest'));
    }

    // ── Imágenes ────────────────────────────────────────────────────

    public function test_strips_image_metadata_and_flags_the_file_as_optimized(): void
    {
        $jpeg = $this->jpeg();
        $withComment = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', 13) . 'hello world' . substr($jpeg, 2);

        $ingested = $this->ingest('a.jpg', $withComment);

        $this->assertTrue($ingested->optimized);
        $this->assertStringNotContainsString('hello world', Storage::disk('local')->get($ingested->storedPath));
        $this->assertDatabaseHas('file_security_events', ['status' => 'optimized', 'original_name' => 'a.jpg']);
    }

    public function test_downscales_images_above_the_configured_maximum(): void
    {
        $ingested = $this->ingest('grande.jpg', $this->jpeg(3200, 1600));

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($ingested->storedPath));
        $this->assertLessThanOrEqual(2560, $width);
        $this->assertLessThanOrEqual(2560, $height);
    }

    public function test_rejects_code_hidden_in_image_metadata(): void
    {
        $jpeg = $this->jpeg();
        $payload = $this->phpTag() . ' echo 1; ?>';
        $bad = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);

        $this->expectException(FileRejectedException::class);

        $this->ingest('a.jpg', $bad);
    }

    public function test_rejects_payload_appended_after_the_end_of_the_image(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('a.jpg', $this->jpeg() . $this->scriptTag());
    }

    public function test_rejects_corrupt_image(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('a.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('x', 50));
    }

    // ── SVG ─────────────────────────────────────────────────────────

    public function test_svg_is_sanitized_and_normalized(): void
    {
        $svg = '<?xml version="1.0"?><!-- editor --><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><metadata>x</metadata><rect width="10" height="10"/></svg>';

        $ingested = $this->ingest('plano.svg', $svg);

        $stored = Storage::disk('local')->get($ingested->storedPath);
        $this->assertStringNotContainsString('metadata', $stored);
        $this->assertStringNotContainsString('<!--', $stored);
        $this->assertSame('image/svg+xml', $ingested->mimeType);
    }

    public function test_svg_with_script_or_event_handler_is_rejected(): void
    {
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg">' . $this->scriptTag() . '</svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
        ] as $svg) {
            try {
                $this->ingest('p.svg', $svg);
                $this->fail('Debió rechazarse: ' . $svg);
            } catch (FileRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── PDF ─────────────────────────────────────────────────────────

    private function pdf(string $catalogExtra = ''): string
    {
        $stream = str_repeat("BT /F1 12 Tf 72 720 Td (Plano de obra) Tj ET\n", 200);
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R {$catalogExtra} >>",
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            5 => '<< /Producer (ProgramaSecreto) >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out . "trailer\n<< /Size 6 /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    public function test_pdf_with_uncompressed_streams_is_compressed_and_loses_its_info_dictionary(): void
    {
        $original = $this->pdf();

        $ingested = $this->ingest('plano.pdf', $original);

        $stored = Storage::disk('local')->get($ingested->storedPath);
        $this->assertTrue($ingested->optimized);
        $this->assertLessThan(strlen($original) * 0.5, strlen($stored));
        $this->assertStringStartsWith('%PDF-', $stored);
        $this->assertStringNotContainsString('ProgramaSecreto', $stored);
    }

    public function test_pdf_with_javascript_or_launch_actions_is_rejected(): void
    {
        foreach (['JavaScript', 'Launch'] as $action) {
            try {
                $this->ingest('a.pdf', $this->pdf("/OpenAction << /S /{$action} /JS (x) >>"));
                $this->fail("Debió rechazarse /{$action}");
            } catch (FileRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_pdf_with_obfuscated_action_name_is_rejected(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('a.pdf', $this->pdf('/OpenAction << /S /J#61va' . 'Script >>'));
    }

    public function test_pdf_with_harmless_open_action_is_accepted(): void
    {
        $ingested = $this->ingest('a.pdf', $this->pdf('/OpenAction [3 0 R /Fit]'));

        Storage::disk('local')->assertExists($ingested->storedPath);
    }

    // ── Hojas de cálculo y texto ────────────────────────────────────

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $this->tempFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function xlsxEntries(): array
    {
        return ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>', 'xl/worksheets/sheet1.xml' => '<sheet/>'];
    }

    public function test_valid_xlsx_is_accepted_with_canonical_mime(): void
    {
        $ingested = $this->ingest('cubicacion.xlsx', $this->zip($this->xlsxEntries()));

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $ingested->mimeType);
    }

    public function test_xlsx_with_macros_or_embedded_executables_is_rejected(): void
    {
        foreach (['xl/vbaProject.bin', 'xl/embeddings/a.exe'] as $entry) {
            try {
                $this->ingest('a.xlsx', $this->zip($this->xlsxEntries() + [$entry => 'x']));
                $this->fail("Debió rechazarse {$entry}");
            } catch (FileRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_zip_renamed_as_spreadsheet_is_rejected(): void
    {
        $this->expectException(FileRejectedException::class);

        $this->ingest('a.xlsx', $this->zip(['notas.txt' => 'hola']));
    }

    public function test_csv_is_normalized_to_utf8_with_unix_line_endings(): void
    {
        $ingested = $this->ingest('datos.csv', "nombre;cantidad\r\nMar\xEDa;3\r\n");

        $stored = Storage::disk('local')->get($ingested->storedPath);
        $this->assertStringContainsString('María', $stored);
        $this->assertStringNotContainsString("\r", $stored);
    }

    public function test_csv_with_command_injection_formula_is_rejected_but_normal_formulas_pass(): void
    {
        $this->ingest('ok.csv', "a,b\n=SUM(A1:A3),-5\n");

        $this->expectException(FileRejectedException::class);
        $this->ingest('mal.csv', "a,b\n=cmd|' /C calc'!A0,1\n");
    }

    public function test_dwg_requires_its_version_signature(): void
    {
        $this->ingest('plano.dwg', 'AC1015' . str_repeat("\0", 64));

        $this->expectException(FileRejectedException::class);
        $this->ingest('falso.dwg', str_repeat('x', 64));
    }

    public function test_accepted_unmodified_file_is_logged_as_accepted(): void
    {
        $this->ingest('plano.dwg', 'AC1015' . str_repeat("\0", 64));

        $this->assertSame(1, FileSecurityEvent::where('status', 'accepted')->count());
    }
}
