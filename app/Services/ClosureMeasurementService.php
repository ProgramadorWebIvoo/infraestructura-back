<?php

namespace App\Services;

use App\Models\ProjectClosureReport;
use Illuminate\Validation\ValidationException;

/**
 * Mediciones independientes del cierre: el residente registra lo que verificó
 * en obra y su medición rige el finiquito; Auditoría solo aprueba o rechaza.
 * Se admiten aumentos y disminuciones respecto a lo contratado; toda
 * discrepancia contra lo declarado exige nota.
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

            if ($error = $this->validateQuantity($item->name, $quantity, $quantity !== (float) $item->contracted_quantity, $note, 'lo contratado')) {
                $errors["items.{$item->id}"] = $error;
                continue;
            }

            $item->update(['resident_quantity' => $quantity, 'resident_note' => $note]);
        }

        $this->failIfAny($errors);
    }

    /**
     * Alinea lo contratado del informe con la cantidad vigente (contratado + modificaciones
     * aprobadas). Lo que el contratista aún no editó (ejecutado = contratado anterior) sigue
     * a la nueva cantidad; lo ya declarado se conserva y se valida al enviar.
     *
     * @param array<string, array{final: float}> $effective indexado por project_material_id
     */
    public function syncContracted(ProjectClosureReport $report, array $effective): void
    {
        foreach ($report->items as $item) {
            $final = $effective[$item->project_material_id]['final'] ?? null;
            if ($final === null) {
                continue;
            }
            $item->update([
                'contracted_quantity' => $final,
                'executed_quantity' => $item->executed_quantity === $item->contracted_quantity ? $final : $item->executed_quantity,
            ]);
        }
    }

    /** Un rechazo obliga a medir de nuevo: se descarta la medición del residente. */
    public function reset(ProjectClosureReport $report): void
    {
        $report->items()->update(['resident_quantity' => null, 'resident_note' => null]);
    }

    private function validateQuantity(string $name, float $quantity, bool $differs, ?string $note, string $against): ?string
    {
        if ($quantity < 0) {
            return "La cantidad de «{$name}» no puede ser negativa.";
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
