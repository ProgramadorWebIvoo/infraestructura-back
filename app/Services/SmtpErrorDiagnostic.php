<?php

namespace App\Services;

/**
 * Traduce fallos del envío SMTP de prueba a mensajes accionables, en vez de
 * exponer el texto crudo de Symfony Mailer ("Connection ... closed unexpectedly").
 */
class SmtpErrorDiagnostic
{
    /**
     * Validaciones previas a conectar. Devuelve el mensaje del primer problema
     * detectado, o null si la configuración es plausible.
     *
     * @param array{host?:mixed,port?:mixed,encryption?:mixed,username?:mixed,password?:mixed,from?:mixed} $cfg
     */
    public function preflight(array $cfg): ?string
    {
        $host = trim((string) ($cfg['host'] ?? ''));
        $port = (int) ($cfg['port'] ?? 0);
        $from = trim((string) ($cfg['from'] ?? ''));

        if ($host === '') {
            return 'Falta el servidor SMTP (host). Ejemplo para Gmail: smtp.gmail.com.';
        }
        if ($port < 1 || $port > 65535) {
            return "El puerto SMTP \"{$cfg['port']}\" no es válido. Usa 587 (TLS) o 465 (SSL).";
        }
        if ($from === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'El correo remitente (From) está vacío o no es válido. Sin remitente ningún correo puede enviarse.';
        }
        if ($host !== 'localhost' && ! filter_var($host, FILTER_VALIDATE_IP) && gethostbyname($host) === $host) {
            return "El servidor \"{$host}\" no existe (falló la resolución DNS). Revisa que el host esté bien escrito"
                .($this->looksLikeGoogle($host) ? ': el de Gmail es smtp.gmail.com.' : '.');
        }
        if (trim((string) ($cfg['username'] ?? '')) !== '' && trim((string) ($cfg['password'] ?? '')) === '') {
            return 'Hay usuario SMTP pero la contraseña está vacía.';
        }

        return null;
    }

    /** Mensaje específico para una excepción lanzada durante el envío. */
    public function diagnose(\Throwable $e, array $cfg): string
    {
        $raw = $e->getMessage();
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 0);
        $enc = strtolower((string) ($cfg['encryption'] ?? ''));

        return match (true) {
            (bool) preg_match('/\b535\b|authenticat|username and password not accepted|invalid credentials/i', $raw)
                => $this->authFailure($host),
            (bool) preg_match('/\b(534|530)\b|application-specific password|5\.7\.9|5\.7\.0/i', $raw)
                => 'El servidor exige una contraseña de aplicación (o autenticación previa) en vez de la contraseña normal de la cuenta. En Gmail: activa la verificación en 2 pasos y genera una en myaccount.google.com/apppasswords.',
            (bool) preg_match('/connection refused|actively refused|\(111\)|\(10061\)/i', $raw)
                => "El servidor {$host} rechazó la conexión en el puerto {$port}. Verifica que el puerto sea el correcto (587 con TLS, 465 con SSL).",
            (bool) preg_match('/timed out|timeout/i', $raw)
                => "Se agotó el tiempo conectando a {$host}:{$port}. Un firewall, antivirus o la red bloquean la salida por ese puerto.",
            (bool) preg_match('/closed unexpectedly|unexpected response|expected response code/i', $raw)
                => $this->protocolMismatch($host, $port, $enc),
            (bool) preg_match('/ssl|tls|certificate|crypto/i', $raw)
                => "Falló el cifrado TLS/SSL con {$host}:{$port} ({$this->trim($raw)}). Revisa que el cifrado coincida con el puerto: 587 = TLS, 465 = SSL.",
            (bool) preg_match('/getaddrinfo|could not resolve|name or service not known|php_network_getaddresses/i', $raw)
                => "No se pudo resolver el servidor \"{$host}\". Revisa que esté bien escrito y que haya conexión a internet.",
            (bool) preg_match('/"From" or a "Sender"/i', $raw)
                => 'El correo remitente (From) está vacío. Configúralo en la sección SMTP.',
            (bool) preg_match('/\b55[0-4]\b|recipient|mailbox unavailable|relay/i', $raw)
                => 'El servidor aceptó la conexión pero rechazó el envío (destinatario o remitente no permitido, o relay denegado). Detalle: '.$this->trim($raw),
            default => 'Error SMTP no identificado: '.$this->trim($raw),
        };
    }

    private function authFailure(string $host): string
    {
        $extra = $this->looksLikeGoogle($host)
            ? ' Con Gmail debes usar una contraseña de aplicación de 16 caracteres, no la clave de la cuenta.'
            : '';

        return "El servidor rechazó el usuario o la contraseña.{$extra}";
    }

    private function protocolMismatch(string $host, int $port, string $enc): string
    {
        $hint = match (true) {
            $this->looksLikeGoogle($host) && strcasecmp($host, 'smtp.gmail.com') !== 0 => "\"{$host}\" no es el servidor de Gmail y rechaza conexiones autenticadas de cuentas personales: usa smtp.gmail.com.",
            $port === 465 && $enc !== 'ssl' => 'El puerto 465 requiere cifrado SSL.',
            $port === 587 && $enc === 'ssl' => 'El puerto 587 requiere cifrado TLS, no SSL.',
            $port === 25 => 'El puerto 25 suele estar bloqueado por los proveedores de internet; prueba 587.',
            default => 'Puede ser un puerto o cifrado que no corresponde a este servidor.',
        };

        return "{$host}:{$port} cerró la conexión antes de completar el diálogo SMTP. {$hint}";
    }

    private function looksLikeGoogle(string $host): bool
    {
        return (bool) preg_match('/google|gmail/i', $host);
    }

    private function trim(string $raw): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', $raw) ?? $raw, 0, 200, '…');
    }
}
