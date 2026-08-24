<?php

namespace App\Services\AI;

/**
 * Aisla lo único que varía entre tipos de evaluación de IA (prompt del
 * sistema, construcción del prompt de usuario, y normalización de la
 * respuesta) del transporte HTTP por proveedor (BaseAIProvider y sus
 * subclases concretas), que es idéntico sin importar qué se está evaluando.
 */
interface EvaluationStrategyInterface
{
    /** Clave usada en AiUsageLog.endpoint, ej. 'evaluate-proposals' | 'evaluate-dossier'. */
    public function endpointKey(): string;

    public function buildSystemPrompt(): string;

    /** Recibe el payload crudo; debe sanitizar sus propios campos de texto libre vía $sanitizer. */
    public function buildUserPrompt(array $payload, callable $sanitizer): string;

    /** Coacciona el JSON decodificado al esquema garantizado de este tipo de evaluación. */
    public function normalizeResult(array $data, string $provider): array;
}
