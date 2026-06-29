<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contractor extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'specialty',
        'rating',
        'contact',
        'registration_source',
        'status',
    ];

    protected $casts = [
        'rating' => 'float',
    ];
}
