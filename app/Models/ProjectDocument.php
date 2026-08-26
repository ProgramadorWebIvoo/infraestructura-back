<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'document_group_id',
        'version_number',
        'document_type',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'version_number' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Todas las versiones del mismo documento lógico, de más antigua a más reciente. */
    public function versions()
    {
        return $this->hasMany(self::class, 'document_group_id', 'document_group_id')->orderBy('version_number');
    }

    /**
     * Solo la última versión de cada grupo — `MAX(id)` en vez de
     * `MAX(version_number)` porque `id` es autoincrement estrictamente
     * creciente y version_number siempre avanza junto con él dentro de un
     * grupo (nunca se insertan versiones fuera de orden).
     */
    public function scopeLatestVersionOnly($query)
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')->from('project_documents')->groupBy('document_group_id');
        });
    }
}
