<?php

/**
 * Matriz de acceso a rutas del SPA por rol. Fuente única de verdad para
 * GET /api/auth/permissions — el frontend ya no la hardcodea.
 *
 * Esto controla navegación/UI, no autorización real de endpoints (eso lo
 * hace el middleware `role:` en routes/api.php); un rol nuevo o una ruta
 * nueva se agregan acá una sola vez.
 */
return [
    'SUPERADMIN' => ['/presidencia', '/infraestructura', '/cierre-obra', '/procura', '/analistas', '/finanzas', '/catalogos', '/usuarios', '/config-proveedores', '/config-materiales', '/config-ia', '/config-app'],
    'ADMIN' => ['/infraestructura', '/cierre-obra', '/procura', '/analistas', '/finanzas', '/catalogos', '/usuarios', '/config-proveedores', '/config-materiales', '/config-ia', '/config-app'],
    'PRESIDENCIA' => ['/presidencia', '/catalogos'],
    'INFRAESTRUCTURA' => ['/infraestructura'],
    'CIERRE_DE_OBRA' => ['/cierre-obra'],
    'PROCURA' => ['/procura', '/catalogos'],
    'ANALISTA' => ['/analistas'],
    'FINANZAS' => ['/finanzas'],
    'CATALOGOS' => ['/catalogos'],
];
