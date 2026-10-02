<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;

/**
 * Límites reales de subida (servidor + ajustes de la app) para que el
 * frontend avise antes de enviar. Pública: también la consumen los portales
 * sin sesión (proveedores, cierre público); no expone nada sensible.
 */
class UploadLimitsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => UploadLimits::toArray()]);
    }
}
