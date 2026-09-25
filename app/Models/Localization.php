<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Localization extends Model
{
    public const TYPES = ['TIENDA', 'PLANTA', 'OFICINA', 'OTRO'];

    protected $fillable = ['title', 'address', 'city', 'region', 'type', 'notes', 'is_active', 'resident_user_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** Texto que se copia a `projects.location` (S9). */
    public function locationLabel(): string
    {
        return "{$this->title} — {$this->city}";
    }
}
