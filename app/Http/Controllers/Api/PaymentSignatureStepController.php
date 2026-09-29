<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentSignatureStepRequest;
use App\Http\Resources\PaymentSignatureStepResource;
use App\Models\ConfigAuditLog;
use App\Models\PaymentSignatureStep;

/** Cadenas de firma configurables por tipo de pago (F4 Bloque C, D2). */
class PaymentSignatureStepController extends Controller
{
    public function index()
    {
        $steps = PaymentSignatureStep::with('user:id,name')->orderBy('payment_type')->orderBy('step_order')->get();

        return PaymentSignatureStepResource::collection($steps);
    }

    public function store(StorePaymentSignatureStepRequest $request)
    {
        $data = $request->validated();

        $step = PaymentSignatureStep::create([
            'payment_type' => $data['paymentType'],
            'step_order' => $data['stepOrder'],
            'role' => $data['role'] ?? null,
            'user_id' => $data['userId'] ?? null,
            'label' => strip_tags($data['label']),
            'is_active' => $data['isActive'] ?? true,
            'is_required' => $data['isRequired'] ?? true,
        ]);
        $step->load('user:id,name');

        $auditLog = ConfigAuditLog::recordAdminAction('payment_signature_step', 'Alta de paso de firma', null, null, "Tipo: {$step->payment_type} / Orden: {$step->step_order} / {$step->label}");

        return response()->json([
            ...(new PaymentSignatureStepResource($step))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function update(StorePaymentSignatureStepRequest $request, PaymentSignatureStep $paymentSignatureStep)
    {
        $data = $request->validated();

        $paymentSignatureStep->update([
            ...(isset($data['paymentType']) ? ['payment_type' => $data['paymentType']] : []),
            ...(isset($data['stepOrder']) ? ['step_order' => $data['stepOrder']] : []),
            ...(array_key_exists('role', $data) ? ['role' => $data['role']] : []),
            ...(array_key_exists('userId', $data) ? ['user_id' => $data['userId']] : []),
            ...(isset($data['label']) ? ['label' => strip_tags($data['label'])] : []),
            ...(isset($data['isActive']) ? ['is_active' => $data['isActive']] : []),
            ...(isset($data['isRequired']) ? ['is_required' => $data['isRequired']] : []),
        ]);
        $paymentSignatureStep->load('user:id,name');

        $auditLog = ConfigAuditLog::recordAdminAction('payment_signature_step', 'Modificacion de paso de firma', null, null, "Tipo: {$paymentSignatureStep->payment_type} / Orden: {$paymentSignatureStep->step_order} / {$paymentSignatureStep->label}");

        return response()->json([
            ...(new PaymentSignatureStepResource($paymentSignatureStep))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function destroy(PaymentSignatureStep $paymentSignatureStep)
    {
        $label = "{$paymentSignatureStep->payment_type} / {$paymentSignatureStep->label}";
        $paymentSignatureStep->delete();

        $auditLog = ConfigAuditLog::recordAdminAction('payment_signature_step', 'Baja de paso de firma', null, null, "Paso: {$label}");

        return response()->json(['auditLog' => $auditLog->toApiPayload()]);
    }
}
