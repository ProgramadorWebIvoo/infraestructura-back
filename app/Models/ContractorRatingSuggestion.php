<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorRatingSuggestion extends Model
{
    protected $fillable = [
        'contractor_code',
        'current_rating',
        'suggested_rating',
        'confidence_score',
        'rationale',
        'provider',
        'source',
    ];

    protected $casts = [
        'current_rating' => 'float',
        'suggested_rating' => 'float',
        'confidence_score' => 'integer',
    ];

    public function contractor()
    {
        return $this->belongsTo(Contractor::class, 'contractor_code', 'code');
    }
}
