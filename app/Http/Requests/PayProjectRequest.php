<?php

namespace App\Http\Requests;

use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paymentType' => ['required', Rule::in(['ADVANCE', 'FINAL'])],
            // Importe en moneda base (lo que calculan las pantallas de Finanzas y
            // se contrasta con la orden vigente).
            'amount' => ['required', 'numeric', 'min:0'],
            // Cómo se pagó realmente: obligatorio y auditable (ver PaymentSettlementService).
            'paymentMode' => ['required', Rule::in(PaymentSettlementService::MODES)],
            'paidAmount' => ['required', 'numeric', 'gt:0'],
            'paidCurrency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3,10}$/'],
            'appliedRate' => ['nullable', 'numeric', 'gt:0'],
            'appliedRateSource' => ['nullable', 'string', Rule::in(PaymentSettlementService::RATE_SOURCES)],
            'differenceReason' => ['nullable', 'string', 'max:500'],
            'paidDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'bank' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
