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

    /** Detalle de una orden con su línea de firmas y verificación de integridad — sin enlace ni pantalla nueva (D11), consumida desde la orden en Finanzas/Procura. */
    public function show(PaymentOrder $paymentOrder)
    {
        $paymentOrder->load(['elaboratedBy:id,name', 'signatures.user:id,name', 'signatures.step']);
        $user = auth()->user();
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
}
