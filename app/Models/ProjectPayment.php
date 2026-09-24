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
        'currency',
        'bank',
        'reference',
        'comprobante_document_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_date' => 'date:Y-m-d',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function proposal()
    {
        return $this->belongsTo(ProjectProposal::class, 'proposal_id');
    }

    public function comprobante()
    {
        return $this->belongsTo(ProjectDocument::class, 'comprobante_document_id')->withTrashed();
    }
}
