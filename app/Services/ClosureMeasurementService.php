<?php

namespace App\Services;

use App\Models\ProjectClosureReport;
use Illuminate\Validation\ValidationException;

/**
 * Mediciones independientes del cierre: el residente registra lo que verificó
 * en obra y su medición rige el finiquito; Auditoría solo aprueba o rechaza.
 * Toda cantidad se limita a lo contratado (las extras van por modificación de
 * obra) y toda discrepancia contra lo declarado exige nota.
 */
class ClosureMeasurementService
{
    /** @param array<int, array{id:int, residentQuantity:float|int|string, note?:?string}> $rows */
    public function recordResident(ProjectClosureReport $report, array $rows): void
    {
        $byId = collect($rows)->keyBy('id');
        $errors = [];

        foreach ($report->items as $item) {
            $row = $byId->get($item->id);
            if ($row === null) {
                $errors["items.{$item->id}"] = "Registre la cantidad verificada de «{$item->name}».";
                continue;
            }

            $quantity = (float) $row['residentQuantity'];
            $note = $row['note'] ?? null;

            if ($error = $this->validateQuantity($item->name, $quantity, $item->contracted_quantity, $quantity !== $item->executed_quantity, $note, 'lo declarado por el contratista')) {
                $errors["items.{$item->id}"] = $error;
                continue;
            }

            $item->update(['resident_quantity' => $quantity, 'resident_note' => $note]);
        }

        $this->failIfAny($errors);
    }

    /** Un rechazo obliga a medir de nuevo: se descarta la medición del residente. */
    public function reset(ProjectClosureReport $report): void
    {
        $report->items()->update(['resident_quantity' => null, 'resident_note' => null]);
    }

    private function validateQuantity(string $name, float $quantity, float $contracted, bool $differs, ?string $note, string $against): ?string
    {
        if ($quantity < 0 || $quantity > $contracted) {
            return "La cantidad de «{$name}» debe estar entre 0 y lo contratado ({$contracted}); las extras se gestionan como modificación de obra.";
        }

        return ($differs && blank($note)) ? "Justifique la diferencia de «{$name}» respecto a {$against}." : null;
    }

    private function failIfAny(array $errors): void
    {
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
