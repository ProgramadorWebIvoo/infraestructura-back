<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 50)), 200);

        return AuditLog::latest('logged_at')
            ->paginate($perPage)
            ->through(fn ($log) => [
                'id' => $log->id,
                'projectId' => $log->project_id,
                'projectTitle' => $log->project_title_snapshot,
                'role' => $log->role,
                'userName' => $log->user_name_snapshot,
                'action' => $log->action,
                'timestamp' => optional($log->logged_at)->format('Y-m-d H:i'),
                'details' => $log->details,
                'observations' => $log->observations,
            ]);
    }
}
