<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal privado para actualizaciones de tasas de cambio — cualquier usuario autenticado
Broadcast::channel('exchange-rates', function ($user) {
    return $user !== null;
});

// Resultado de la evaluación IA de propuestas (EvaluateProposalsWithAIJob /
// AIEvaluationFinished) — mismos roles que pueden disparar el endpoint
// POST /api/ai/evaluate-proposals (routes/api.php).
Broadcast::channel('project.{projectId}.ai-evaluation', function ($user) {
    return $user !== null && in_array($user->role, ['PROCURA', 'ANALISTA', 'ADMIN', 'SUPERADMIN'], true);
});
