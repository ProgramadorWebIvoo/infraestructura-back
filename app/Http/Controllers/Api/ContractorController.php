<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractorController extends Controller
{
    private const CONTRACTOR_STATUSES = ['PENDING_REVIEW', 'ACTIVE', 'INACTIVE'];

    public function index()
    {
        $contractors = Contractor::orderBy('name')->get();

        return response()->json($contractors->map(fn ($c) => [
            'code'               => $c->code,
            'name'               => $c->name,
            'specialty'          => $c->specialty,
            'rating'             => $c->rating,
            'contact'            => $c->contact,
            'registrationSource' => $c->registration_source,
            'status'             => $c->status,
            'createdAt'          => optional($c->created_at)->format('Y-m-d H:i'),
            'updatedAt'          => optional($c->updated_at)->format('Y-m-d H:i'),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:180'],
            'specialty'  => ['required', 'string', 'max:180'],
            'contact'    => ['required', 'string', 'max:180'],
            'rating'     => ['nullable', 'numeric', 'min:0', 'max:5'],
            'status'     => ['sometimes', Rule::in(self::CONTRACTOR_STATUSES)],
        ]);

        $data['name'] = strip_tags($data['name']);
        $data['specialty'] = strip_tags($data['specialty']);
        $data['contact'] = strip_tags($data['contact']);

        $contractor = DB::transaction(function () use ($data) {
            $data['code'] ??= Contractor::nextCode();
            $data['rating'] ??= 4.0;
            $data['registration_source'] = 'INTERNAL';
            $data['status'] = $data['status'] ?? 'ACTIVE';

            return Contractor::create($data);
        });

        return response()->json([
            'code'               => $contractor->code,
            'name'               => $contractor->name,
            'specialty'          => $contractor->specialty,
            'rating'             => $contractor->rating,
            'contact'            => $contractor->contact,
            'registrationSource' => $contractor->registration_source,
            'status'             => $contractor->status,
            'createdAt'          => $contractor->created_at?->format('Y-m-d H:i'),
            'updatedAt'          => $contractor->updated_at?->format('Y-m-d H:i'),
        ], 201);
    }

    public function show(Contractor $contractor)
    {
        return response()->json([
            'code'               => $contractor->code,
            'name'               => $contractor->name,
            'specialty'          => $contractor->specialty,
            'rating'             => $contractor->rating,
            'contact'            => $contractor->contact,
            'registrationSource' => $contractor->registration_source,
            'status'             => $contractor->status,
            'createdAt'          => optional($contractor->created_at)->format('Y-m-d H:i'),
            'updatedAt'          => optional($contractor->updated_at)->format('Y-m-d H:i'),
        ]);
    }

    public function update(Request $request, Contractor $contractor)
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:180'],
            'specialty' => ['sometimes', 'string', 'max:180'],
            'contact'   => ['sometimes', 'string', 'max:180'],
            'rating'    => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'status'    => ['sometimes', Rule::in(self::CONTRACTOR_STATUSES)],
        ]);

        if (isset($data['name']))      $data['name'] = strip_tags($data['name']);
        if (isset($data['specialty']))  $data['specialty'] = strip_tags($data['specialty']);
        if (isset($data['contact']))    $data['contact'] = strip_tags($data['contact']);
        if (isset($data['rating']))     $data['rating'] = round($data['rating'], 1);

        $contractor->update($data);

        return response()->json([
            'code'               => $contractor->code,
            'name'               => $contractor->name,
            'specialty'          => $contractor->specialty,
            'rating'             => $contractor->rating,
            'contact'            => $contractor->contact,
            'registrationSource' => $contractor->registration_source,
            'status'             => $contractor->status,
            'createdAt'          => optional($contractor->created_at)->format('Y-m-d H:i'),
            'updatedAt'          => optional($contractor->updated_at)->format('Y-m-d H:i'),
        ]);
    }

    public function toggleStatus(Contractor $contractor)
    {
        $map = [
            'PENDING_REVIEW' => 'ACTIVE',
            'ACTIVE'         => 'INACTIVE',
            'INACTIVE'       => 'ACTIVE',
        ];

        $contractor->status = $map[$contractor->status] ?? 'ACTIVE';
        $contractor->save();

        return response()->json([
            'code'   => $contractor->code,
            'status' => $contractor->status,
        ]);
    }
}
