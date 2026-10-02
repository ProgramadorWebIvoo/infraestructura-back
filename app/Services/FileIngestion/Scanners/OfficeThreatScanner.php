<?php

namespace App\Services\FileIngestion\Scanners;

use App\Exceptions\FileRejectedException;
use App\Services\FileIngestion\DetectedFile;
use ZipArchive;

/**
 * Hojas de cálculo: XLSX/ODS son ZIP (se inspeccionan sus entradas: macros,
 * objetos OLE/ActiveX incrustados, ejecutables, rutas peligrosas, bombas de
 * descompresión) y XLS es un contenedor OLE (se buscan los almacenes de
 * macros VBA y objetos incrustados).
 */
class OfficeThreatScanner implements FileThreatScanner
{
    private const MAX_ENTRIES = 5000;
    private const MAX_UNCOMPRESSED_BYTES = 300 * 1024 * 1024;
    private const MAX_INSPECTED_XML_BYTES = 20 * 1024 * 1024;

    private const FORBIDDEN_ENTRY = '#(^|/)(vbaProject\.bin|xl/embeddings/|xl/activeX/|xl/macrosheets/|basic/|scripts/)|\.(exe|dll|bat|cmd|ps1|vbs|js|jar|class|sh|scr|com|msi)$#i';

    /** Marcadores en UTF-16LE de macros VBA y objetos OLE incrustados dentro de un XLS. */
    private const OLE_MARKERS = ['_VBA_PROJECT', 'Macros', 'Ole10Native', 'VBA'];

    public function supports(DetectedFile $file): bool
    {
        return $file->isKind('xlsx', 'ods', 'xls');
    }

    public function assertSafe(DetectedFile $file, string $contents): void
    {
        if ($file->isKind('xls')) {
            $this->assertNoOleMacros($contents);

            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($file->path, ZipArchive::RDONLY) !== true) {
            throw new FileRejectedException("El archivo «{$file->originalName}» está dañado o no es válido.");
        }

        try {
            $this->assertSafeEntries($zip);
            $this->assertExpectedStructure($zip, $file);
            $this->assertNoMacroDeclarations($zip, $file);
        } finally {
            $zip->close();
        }
    }

    private function assertSafeEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new FileRejectedException('El archivo contiene demasiados elementos internos.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            $total += (int) ($stat['size'] ?? 0);

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                throw new FileRejectedException('El archivo contiene rutas internas no permitidas.');
            }

            if (preg_match(self::FORBIDDEN_ENTRY, $name) === 1) {
                throw new FileRejectedException('El archivo contiene macros, objetos incrustados o ejecutables y no está permitido.');
            }
        }

        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new FileRejectedException('El archivo descomprimido excede el tamaño permitido.');
        }
    }

    private function assertExpectedStructure(ZipArchive $zip, DetectedFile $file): void
    {
        $valid = $file->isKind('xlsx')
            ? $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false
            : trim((string) $zip->getFromName('mimetype', 256)) === 'application/vnd.oasis.opendocument.spreadsheet';

        if (!$valid) {
            throw new FileRejectedException("El contenido de «{$file->originalName}» no coincide con su extensión declarada.");
        }
    }

    /** Un .xlsx "macroEnabled" (xlsm renombrado) o un ODS con escuchadores de script. */
    private function assertNoMacroDeclarations(ZipArchive $zip, DetectedFile $file): void
    {
        $entry = $file->isKind('xlsx') ? '[Content_Types].xml' : 'content.xml';
        $xml = (string) $zip->getFromName($entry, self::MAX_INSPECTED_XML_BYTES);

        $pattern = $file->isKind('xlsx') ? '/macroEnabled|vbaProject/i' : '/<script:|office:scripts|event-listener/i';
        if (preg_match($pattern, $xml) === 1) {
            throw new FileRejectedException('El archivo contiene macros y no está permitido.');
        }
    }

    private function assertNoOleMacros(string $contents): void
    {
        foreach (self::OLE_MARKERS as $marker) {
            $utf16 = implode("\0", str_split($marker)) . "\0";
            if (str_contains($contents, $utf16)) {
                throw new FileRejectedException('El archivo contiene macros u objetos incrustados y no está permitido.');
            }
        }
    }
}
