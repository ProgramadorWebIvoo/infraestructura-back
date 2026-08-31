<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contractor extends Model
{
    use HasFactory;

    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'rif',
        'specialty',
        'rating',
        'email',
        'phone',
        'registration_source',
        'status',
    ];

    /**
     * Formato RIF venezolano: letra de tipo de contribuyente (V/E/J/P/G) +
     * 8 dígitos + dígito verificador, con o sin guiones (J-12345678-9 o
     * J123456789). No valida el dígito verificador matemáticamente —
     * solo la forma, igual que el resto de validaciones de formato del
     * sistema (ej. email).
     */
    public const RIF_REGEX = '/^[VEJPGvejpg]-?\d{8}-?\d$/';

    /**
     * Normaliza un RIF a un único formato canónico ("J-12345678-9") antes de
     * validar/guardar. RIF_REGEX acepta guiones opcionales en 2 posiciones
     * independientes — "J123456789", "J-123456789", "J12345678-9" y
     * "J-12345678-9" son 4 strings distintos que representan el MISMO RIF,
     * pero `unique:contractors,rif` compara el string literal en la BD: sin
     * normalizar antes de validar, dos formatos distintos del mismo RIF no
     * chocan entre sí y la regla unique queda burlada en la práctica
     * (bug real detectado en QA: mismo RIF registrado dos veces con guiones
     * en posiciones distintas). Si el input no matchea el formato esperado
     * se devuelve tal cual, sin normalizar — la regla `regex` en el Request
     * es la que lo rechaza después.
     */
    public static function normalizeRif(?string $rif): ?string
    {
        $rif = strtoupper(trim((string) $rif));
        if (!preg_match('/^([VEJPG])-?(\d{8})-?(\d)$/', $rif, $m)) {
            return $rif;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    protected $casts = [
        'rating' => 'float',
    ];

    /**
     * Genera el siguiente código secuencial (CON-301, CON-302, ...).
     * Bloquea la última fila con ese prefijo para evitar colisiones bajo
     * concurrencia; el caller debe envolver la creación en una transacción.
     */
    public static function nextCode(): string
    {
        $last = static::query()
            ->where('code', 'like', 'CON-%')
            ->orderByRaw('CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->first();

        $number = $last ? ((int) substr($last->code, 4)) + 1 : 301;

        return 'CON-' . $number;
    }

    /**
     * Resuelve el `code` de un Contractor a partir de su nombre — usado por
     * CatalogSyncService y PriceEstimationObserver para mapear
     * supplier_name (texto libre en las propuestas) a supplier_code
     * (identidad real del proveedor). Antes cada uno tenía su propia query
     * inline: CatalogSyncService la repetía UNA VEZ POR LÍNEA de la misma
     * propuesta (N+1 real, mismo resultado cada vez), y el Observer usaba
     * comparación exacta (`where('name', ...)`) mientras CatalogSyncService
     * usaba case-insensitive (`whereRaw LOWER(name) = ...`) — dos fuentes
     * podían resolver distinto para el mismo proveedor según mayúsculas.
     * Cacheado 1h: el nombre de un Contractor cambia muy rara vez (acción
     * manual de ADMIN), y una desincronización de hasta 1h es aceptable
     * frente al costo de invalidar en cada update.
     */
    public static function codeForSupplierName(?string $name): ?string
    {
        $normalized = mb_strtolower(trim((string) $name));
        if ($normalized === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember(
            "contractor_code_for_name:{$normalized}",
            3600,
            fn () => static::whereRaw('LOWER(name) = ?', [$normalized])->value('code')
        );
    }

    public function catalogProducts()
    {
        return $this->hasMany(CatalogProductSupplier::class, 'supplier_code', 'code');
    }

    public function priceHistory()
    {
        return $this->hasMany(ProductPriceHistory::class, 'supplier_code', 'code');
    }
}
