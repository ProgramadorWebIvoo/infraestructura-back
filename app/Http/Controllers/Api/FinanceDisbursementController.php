<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSettlementResource;
use App\Models\ProjectPayment;
use Illuminate\Http\JsonResponse;

/**
 * Diario de egresos de Finanzas: cada pago registrado con todo lo necesario para
 * inspeccionarlo — obra y proveedor, orden de pago, banco/referencia, comprobante,
 * cómo se pagó (moneda, tasa, congelados) y la moneda/tasa de cotización de la oferta
 * adjudicada — sin reconstruirlo a partir de los agregados de cada proyecto.
 */
class FinanceDisbursementController extends Controller
{
    public function index(): JsonResponse
    {
        $payments = ProjectPayment::query()
            ->with([
                'project:id,title,selected_contractor_code',
                'proposal',
                'comprobante',
                'paymentOrder:id,number',
                'contractRateFreeze',
                'paymentRateFreeze',
            ])
            ->orderByDesc('paid_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $payments->map(fn (ProjectPayment $p) => [
            'id' => $p->id,
            'projectId' => $p->project_id,
            'title' => $p->project?->title,
            'contractorCode' => $p->proposal?->contractor_code ?? $p->project?->selected_contractor_code,
            'contractorName' => $p->proposal?->contractor_name_snapshot,
            'type' => $p->payment_type,
            'amount' => $p->amount,
            'paidDate' => optional($p->paid_date)->format('Y-m-d'),
            'bank' => $p->bank,
            'reference' => $p->reference,
            'notes' => $p->notes,
            'orderNumber' => $p->paymentOrder?->number,
            'proof' => $p->comprobante ? ['id' => $p->comprobante->id, 'name' => $p->comprobante->original_name] : null,
            // Lo cotizado y la tasa a base de la oferta: permiten mostrar el importe en su moneda original.
            'quoteCurrency' => $p->proposal?->quote_currency ?? 'USD',
            'fxRateToBase' => $p->proposal?->fx_rate_to_base,
            // Null en pagos anteriores al registro de liquidación.
            'settlement' => $p->payment_mode !== null ? (new PaymentSettlementResource($p))->resolve() : null,
        ])->values()]);
    }
}
