<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    public function modules()
    {
        return DB::table('app_modules')->orderBy('id')->get();
    }

    public function contractors()
    {
        return Contractor::orderBy('name')->get(['code', 'name', 'specialty', 'rating', 'contact', 'status']);
    }

    public function storeContractor(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'unique:contractors,code'],
            'name' => ['required', 'string', 'max:180'],
            'specialty' => ['required', 'string', 'max:180'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'contact' => ['required', 'string', 'max:180'],
        ]);

        $data['code'] ??= $this->nextContractorCode();
        $data['rating'] ??= 4.0;
        $data['registration_source'] = 'PUBLIC_PORTAL';
        $data['status'] = 'PENDING_REVIEW';

        return response()->json(Contractor::create($data), 201);
    }

    public function materials()
    {
        return MaterialCatalog::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'estimatedUnitPrice' => $item->estimated_unit_price,
            ]);
    }

    public function auditLogs()
    {
        return AuditLog::latest('logged_at')->limit(200)->get()->map(fn ($log) => [
            'id' => $log->id,
            'projectId' => $log->project_id,
            'projectTitle' => $log->project_title_snapshot,
            'role' => $log->role,
            'action' => $log->action,
            'timestamp' => optional($log->logged_at)->format('Y-m-d H:i'),
            'details' => $log->details,
        ]);
    }

    private function nextContractorCode(): string
    {
        $last = Contractor::query()
            ->where('code', 'like', 'CON-%')
            ->orderByRaw('CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC')
            ->first();

        $number = $last ? ((int) substr($last->code, 4)) + 1 : 301;

        return 'CON-' . $number;
    }
}
