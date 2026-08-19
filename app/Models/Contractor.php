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
}
