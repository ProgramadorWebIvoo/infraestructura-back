<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentOrderResource;
use App\Http\Resources\PaymentSignatureStepResource;
use App\Models\PaymentOrder;
use App\Services\PaymentOrderService;
use App\Services\PaymentSignatureService;

class PaymentOrderController extends Controller
{
    public function __construct(
        private readonly PaymentOrderService $orders,
        private readonly PaymentSignatureService $signatures,
    ) {
    }

    /** Detalle de una orden con su línea de firmas y verificación de integridad — sin enlace ni pantalla nueva (D11), consumida desde la orden en Finanzas/Procura/Presidencia y desde "Firmas pendientes". */
    public function show(PaymentOrder $paymentOrder)
    {
        $user = auth()->user();
        abort_unless($this->signatures->canViewOrder($paymentOrder, $user), 403);

        $paymentOrder->load([
            'elaboratedBy:id,name',
            'signatures.user:id,name',
            'signatures.step',
            'payment.contractRateFreeze.frozenByUser:id,name',
            'payment.paymentRateFreeze.frozenByUser:id,name',
        ]);
        $nextStep = $this->signatures->nextPendingStep($paymentOrder);

        return response()->json([
            'data' => [
                ...(new PaymentOrderResource($paymentOrder))->resolve(),
                'integrityValid' => $this->orders->verifyIntegrity($paymentOrder),
                'signatureLine' => array_map(
                    fn (array $row) => [
                        'step' => (new PaymentSignatureStepResource($row['step']))->resolve(),
                        'status' => $row['status'],
                        'signedByName' => $row['signature']?->user?->name,
                        'signedAt' => optional($row['signature']?->signed_at)->toIso8601String(),
                    ],
                    $this->signatures->signatureLine($paymentOrder)
                ),
                'canSign' => $nextStep !== null && $nextStep->canBeSignedBy($user),
            ],
        ]);
    }

    /** Firma el próximo paso pendiente de la orden, si le corresponde al usuario autenticado (turno estricto). */
    public function sign(PaymentOrder $paymentOrder)
    {
        $signature = $this->signatures->sign($paymentOrder, auth()->user(), request());

        return response()->json(['data' => ['id' => $signature->id, 'signedAt' => $signature->signed_at->toIso8601String()]], 201);
    }

    /**
     * Bandeja "Firmas pendientes" (F4 Bloque C): sin restricción de rol —
     * cualquier usuario autenticado puede consultar si le corresponde firmar
     * algo, sea cual sea su rol. El sidebar solo muestra la pestaña cuando
     * hasConfiguredSteps es true, para no mostrarla vacía a todo el mundo.
     */
    public function pendingSignatures()
    {
        $user = auth()->user();
        $orders = $this->signatures->pendingSignaturesFor($user)->load(['elaboratedBy:id,name', 'signatures.user:id,name', 'signatures.step']);

        return response()->json([
            'data' => [
                'hasConfiguredSteps' => $this->signatures->hasConfiguredStepsFor($user),
                'orders' => PaymentOrderResource::collection($orders)->resolve(),
            ],
        ]);
    }
}
