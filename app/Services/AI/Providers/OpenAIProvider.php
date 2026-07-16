<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('ai.openai.api_key');
        $this->model   = config('ai.openai.model', 'gpt-4o');
        $this->baseUrl = config('ai.openai.base_url', 'https://api.openai.com/v1');
        $this->timeout = config('ai.timeout', 30);
    }

    public function name(): string
    {
        return 'chatgpt';
    }

    public function evaluate(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY no configurada.');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt   = $this->buildUserPrompt($payload);

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'max_tokens'  => config('ai.openai.max_tokens', 4096),
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Rate limit excedido en OpenAI.');
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenAI error {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            throw new RuntimeException('OpenAI devolvió una respuesta vacía.');
        }

        return $this->parseResponse($content);
    }

    protected function buildSystemPrompt(): string
    {
        return <<<PROMPT
Actúa como un Ingeniero en Infraestructura con 15 años de experiencia
en finanzas de construcción y contratación de obras públicas.
Tu tarea es evaluar propuestas de contratistas para una obra específica
y recomendar la mejor opción de contratación.

Evalúa CRÍTICAMENTE:

1. COSTO TOTAL vs inversión aprobada
2. RELACIÓN costo-beneficio (material + mano de obra)
3. PLAZO DE ENTREGA vs complejidad de la obra
4. % ANTICIPO y riesgo financiero que representa
5. CAPACIDAD del contratista (experiencia, especialidad)
6. OBSERVACIONES (tasa de cambio, garantías, disponibilidad de material, divisa)

Debes responder exclusivamente en JSON, sin markdown ni texto adicional.
El JSON debe tener esta estructura exacta:
{
  "winnerContractorCode": "código del contratista ganador",
  "winnerContractorName": "nombre del contratista ganador",
  "confidenceScore": (número entre 0 y 100),
  "summary": "análisis cualitativo detallado de 3 a 5 párrafos",
  "strengths": ["fortaleza 1", "fortaleza 2", ...],
  "weaknesses": ["debilidad 1", "debilidad 2", ...],
  "riskFactors": ["riesgo 1", "riesgo 2", ...],
  "recommendation": "explicación final de por qué esta es la mejor opción"
}
PROMPT;
    }

    protected function buildUserPrompt(array $payload): string
    {
        $project = $payload['project'];
        $proposals = $payload['proposals'];

        $text = "## PROYECTO\n";
        $text .= "ID: {$project['projectId']}\n";
        $text .= "Título: {$project['projectTitle']}\n";
        $text .= "Descripción: {$project['projectDescription']}\n";
        $text .= "Ubicación: {$project['projectLocation']}\n";
        $text .= "Tipo: {$project['projectType']}\n";
        $text .= "Inversión Autorizada: \${$project['approvedInvestmentAmount']}\n\n";

        $text .= "## PROPUESTAS\n";

        foreach ($proposals as $i => $prop) {
            $text .= "--- Propuesta " . ($i + 1) . " ---\n";
            $text .= "Contratista: {$prop['contractorName']} ({$prop['contractorCode']})\n";
            $text .= "Costo Materiales: \${$prop['materialCost']}\n";
            $text .= "Costo Mano de Obra: \${$prop['laborCost']}\n";
            $text .= "Costo Total: \${$prop['totalCost']}\n";
            $text .= "Entrega: {$prop['deliveryWeeks']} semanas\n";
            $text .= "Anticipo Pactado: {$prop['negotiatedAdvancePercent']}%\n";
            $text .= "Descripción: {$prop['description']}\n";

            if (!empty($prop['observations'])) {
                $text .= "Observaciones: {$prop['observations']}\n";
            }

            $text .= "\n";
        }

        return $text;
    }

    private function parseResponse(string $content): array
    {
        // Limpiar posibles bloques markdown ```json ... ```
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new RuntimeException('No se pudo parsear la respuesta JSON de OpenAI.');
        }

        return $this->normalizeResult($data, 'chatgpt');
    }

    protected function normalizeResult(array $data, string $provider): array
    {
        return [
            'winnerContractorCode' => $data['winnerContractorCode'] ?? '',
            'winnerContractorName' => $data['winnerContractorName'] ?? '',
            'confidenceScore'      => (int) ($data['confidenceScore'] ?? 0),
            'summary'              => $data['summary'] ?? '',
            'strengths'            => $data['strengths'] ?? [],
            'weaknesses'           => $data['weaknesses'] ?? [],
            'riskFactors'          => $data['riskFactors'] ?? [],
            'recommendation'       => $data['recommendation'] ?? '',
            'providerUsed'         => $provider,
        ];
    }
}
