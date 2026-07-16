<?php

namespace App\Services\AI\Providers;

interface AIProviderInterface
{
    /**
     * Nombre del proveedor para identificar en logs y respuesta.
     */
    public function name(): string;

    /**
     * Evalúa las propuestas y devuelve un resultado estructurado.
     *
     * @param array $payload Datos del proyecto y propuestas
     * @return array Con llaves: winnerContractorCode, winnerContractorName,
     *               confidenceScore, summary, strengths[], weaknesses[],
     *               riskFactors[], recommendation, providerUsed
     * @throws \RuntimeException Si la API falla o rate-limited
     */
    public function evaluate(array $payload): array;
}
