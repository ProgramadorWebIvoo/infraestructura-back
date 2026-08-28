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
        'specialty',
        'rating',
        'email',
        'phone',
        'registration_source',
        'status',
    ];

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
