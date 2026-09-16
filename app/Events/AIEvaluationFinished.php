<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Empuja el resultado (o error) de EvaluateProposalsWithAIJob al canal
 * privado del proyecto en cuanto el Job termina, para que el modal de
 * evaluación IA no dependa de polling — ver routes/channels.php para la
 * autorización del canal y EvaluateProposalsWithAIJob::handle() para quién
 * lo dispara.
 *
 * ShouldBroadcastNow (no ShouldBroadcast): ya estamos ejecutando dentro de
 * un Job en cola (ver EvaluateProposalsWithAIJob), así que el broadcast
 * puede hacerse en línea sin encolar un segundo Job para eso.
 */
class AIEvaluationFinished implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param string $projectId
     * @param 'completed'|'failed' $status
     * @param array|null $result Shape igual al que devolvía el endpoint síncrono (winner, score, etc.)
     * @param string|null $error
     */
    public function __construct(
        public string $projectId,
        public string $status,
        public ?array $result = null,
        public ?string $error = null,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.' . $this->projectId . '.ai-evaluation'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai-evaluation.finished';
    }

    public function broadcastWith(): array
    {
        return [
            'projectId' => $this->projectId,
            'status'    => $this->status,
            'data'      => $this->result,
            'error'     => $this->error,
        ];
    }
}
