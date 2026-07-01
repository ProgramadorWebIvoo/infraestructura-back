<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvitation extends Model
{
    protected $table = 'supplier_invitations';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'project_id',
        'supplier_name',
        'supplier_company',
        'supplier_contact',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
