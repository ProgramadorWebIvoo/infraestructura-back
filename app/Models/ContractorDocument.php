<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractorDocument extends Model
{
    use SoftDeletes;

    public const SOURCE_PUBLIC_PORTAL = 'PUBLIC_PORTAL';
    public const SOURCE_INTERNAL = 'INTERNAL';

    protected $fillable = [
        'contractor_code',
        'document_type_id',
        'document_group_id',
        'version_number',
        'stored_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
        'source',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'version_number' => 'integer',
    ];

    public function contractor()
    {
        return $this->belongsTo(Contractor::class, 'contractor_code', 'code');
    }

    public function type()
    {
        return $this->belongsTo(ContractorDocumentType::class, 'document_type_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function versions()
    {
        return $this->hasMany(self::class, 'document_group_id', 'document_group_id')->orderBy('version_number');
    }

    public function scopeLatestVersionOnly($query)
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')->from('contractor_documents')->whereNull('deleted_at')->groupBy('document_group_id');
        });
    }
}
