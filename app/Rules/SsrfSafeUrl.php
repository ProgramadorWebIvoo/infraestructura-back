<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\InvokableRule;

/**
 * Valida que la URL no apunte a direcciones internas (SSRF prevention).
 * Solo permite URLs HTTPS a dominios públicos.
 *
 * Extraído de AiConfigController::ssrfSafeUrl() (closure-based rule) para
 * reutilización y testabilidad independiente.
 */
class SsrfSafeUrl implements InvokableRule
{
    public function __invoke($attribute, $value, $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        // Debe comenzar con https://
        if (!str_starts_with($value, 'https://')) {
            $fail('La URL debe usar HTTPS (conexión segura).');
            return;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if ($host === false || $host === null || $host === '') {
            $fail('La URL no tiene un host válido.');
            return;
        }

        // Rejectar localhost / 127.0.0.1 / 0.0.0.0 / [::1]
        $localHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
        if (in_array(strtolower($host), $localHosts, true)) {
            $fail('No se permite usar direcciones locales (localhost/127.0.0.1).');
            return;
        }

        // Rejectar IPs privadas (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail('No se permite usar IPs privadas o de rangos reservados.');
                return;
            }
            return;
        }

        // El host es un dominio, no una IP literal: resolverlo y validar
        // TODAS las IPs que devuelve. Esto bloquea el caso obvio de un
        // dominio público apuntando a una IP privada/metadata de nube
        // (169.254.169.254, etc.). No protege contra "DNS rebinding" en
        // sentido estricto (TTL≈0, la IP cambia entre esta validación y
        // la request real del provider) — eso requeriría fijar la IP
        // resuelta a nivel de conexión HTTP (handler cURL/Guzzle custom),
        // fuera de alcance de esta validación de formulario.
        $resolvedIps = [];

        $aRecords = @dns_get_record($host, DNS_A);
        foreach ($aRecords ?: [] as $record) {
            if (!empty($record['ip'])) $resolvedIps[] = $record['ip'];
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        foreach ($aaaaRecords ?: [] as $record) {
            if (!empty($record['ipv6'])) $resolvedIps[] = $record['ipv6'];
        }

        if (empty($resolvedIps)) {
            $fail('No se pudo resolver el host de la URL.');
            return;
        }

        foreach (array_unique($resolvedIps) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail('El host de la URL resuelve a una dirección IP privada o reservada.');
                return;
            }
        }
    }
}
