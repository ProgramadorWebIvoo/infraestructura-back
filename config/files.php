<?php

return [
    /*
    | Ghostscript (opcional) para comprimir PDFs con imágenes pesadas. Ruta al
    | ejecutable (`gs`, `gswin64c`). Sin él, la compresión de PDF usa el
    | optimizador PHP, que solo recomprime streams sin comprimir.
    */
    'ghostscript_binary' => env('GHOSTSCRIPT_BINARY'),
    'ghostscript_timeout' => (int) env('GHOSTSCRIPT_TIMEOUT', 60),
];
