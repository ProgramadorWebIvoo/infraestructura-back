<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectClosureReport;
use App\Services\ClosureDebugFixtureService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Fixtures del DEBUG-MODE para probar el cierre posterior a la ejecución. Solo con APP_DEBUG. */
class DebugClosureFixtureController extends Controller
{
    public function __construct(private ClosureDebugFixtureService $fixtures)
    {
    }

    public function callAction($method, $parameters)
    {
        abort_unless(config('app.debug'), 404);

        return parent::callAction($method, $parameters);
    }

    public function index()
    {
        return Project::where('title', 'like', '[DEBUG]%')->latest('created_at')->limit(20)
            ->get(['id', 'title', 'status'])->map(fn (Project $p) => $this->payload($p));
    }

    public function store(Request $request)
    {
        $target = $request->validate(['targetStatus' => ['required', Rule::in(ClosureDebugFixtureService::STEPS)]])['targetStatus'];

        return response()->json($this->payload($this->fixtures->create($request->user(), $target)), 201);
    }

    public function advance(Request $request, Project $project)
    {
        abort_unless(str_starts_with($project->title, '[DEBUG]'), 403, 'Solo se pueden avanzar obras de prueba.');
        $target = $request->validate(['targetStatus' => ['required', Rule::in(ClosureDebugFixtureService::STEPS)]])['targetStatus'];

        return $this->payload($this->fixtures->advanceTo($project, $request->user(), $target));
    }

    private function payload(Project $project): array
    {
        $token = ProjectClosureReport::where('project_id', $project->id)->value('id');
        $frontend = config('app.frontend_url', 'http://localhost:3000');

        return [
            'projectId' => $project->id,
            'title' => $project->title,
            'status' => $project->status,
            'publicUrl' => $token ? "{$frontend}/cierre-publico/{$token}" : null,
        ];
    }
}
