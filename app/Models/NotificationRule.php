<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila = "el rol X recibe notificación de la acción Y por el canal Z".
 * Modelo delgado — la lógica de resolución/caché vive en
 * NotificationRuleResolver, no aquí.
 */
class NotificationRule extends Model
{
    protected $fillable = ['action', 'role', 'channel', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
