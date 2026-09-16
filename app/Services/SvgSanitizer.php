<?php

namespace App\Services;

/**
 * SVG es XML que el navegador ejecuta como si fuera HTML — a diferencia de
 * PNG/JPEG, no basta con validar mime/extensión (ver FileSecurityScanner):
 * hay que remover activamente cualquier vector de XSS antes de permitir que
 * se sirva. Estrategia: allowlist de remoción (quitar lo peligroso conocido)
 * en vez de allowlist de parseo completo — no hay parser XML/SVG en este
 * proyecto y agregar uno (ej. DOMDocument con schema) es sobre-ingeniería
 * para el volumen de SVGs que sube este sistema (planos vectoriales).
 */
class SvgSanitizer
{
    public function sanitize(string $svg): string
    {
        // <script>...</script>
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg) ?? $svg;

        // Atributos on* (onload, onclick, onerror, ...)
        $svg = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $svg) ?? $svg;
        $svg = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $svg) ?? $svg;

        // href/xlink:href con javascript: o data:text/html
        $svg = preg_replace('/(href\s*=\s*["\'])\s*(javascript|data:text\/html)[^"\']*/i', '$1', $svg) ?? $svg;

        // <foreignObject> permite embeber HTML arbitrario dentro del SVG
        $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg) ?? $svg;

        // Entidades externas / DOCTYPE (billion laughs, XXE)
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!ENTITY[^>]*>/i', '', $svg) ?? $svg;

        return $svg;
    }
}
