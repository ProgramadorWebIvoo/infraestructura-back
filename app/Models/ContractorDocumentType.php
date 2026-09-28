<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ContractorDocumentType extends Model
{
    protected $fillable = [
        'key',
        'label',
        'is_required',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const CATALOG_CACHE_KEY = 'contractor_document_types:public';
    public const CATALOG_CACHE_TTL = 300;

    public static function forgetCatalogCache(): void
    {
        Cache::forget(self::CATALOG_CACHE_KEY);
    }

    public function documents()
    {
        return $this->hasMany(ContractorDocument::class, 'document_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }
}
