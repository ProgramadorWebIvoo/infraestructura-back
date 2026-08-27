<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomProductResolution extends Model
{
    protected $primaryKey = 'supplier_material_proposal_line_id';
    public $incrementing = false;

    const UPDATED_AT = null;
    const CREATED_AT = null;

    protected $fillable = [
        'supplier_material_proposal_line_id',
        'resolved_catalog_product_id',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function proposalLine()
    {
        return $this->belongsTo(SupplierMaterialProposalLine::class, 'supplier_material_proposal_line_id');
    }

    public function resolvedProduct()
    {
        return $this->belongsTo(MaterialCatalog::class, 'resolved_catalog_product_id');
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
