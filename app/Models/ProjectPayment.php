<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectPayment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'proposal_id',
        'payment_type',
        'amount',
        'paid_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_date' => 'date:Y-m-d',
    ];
}
