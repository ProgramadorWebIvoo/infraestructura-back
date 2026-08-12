<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Models\MaterialCatalog;

class MaterialController extends Controller
{
    public function index()
    {
        $materials = MaterialCatalog::orderBy('name')->get();

        return response()->json(MaterialResource::collection($materials));
    }

    public function store(StoreMaterialRequest $request)
    {
        $data = $request->validated();

        $data['name'] = strip_tags($data['name']);
        $data['unit'] = strip_tags($data['unit']);

        // Unique constraint on (name, unit) — check manually for readable error
        $exists = MaterialCatalog::where('name', $data['name'])
            ->where('unit', $data['unit'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "Ya existe un material con el nombre \"{$data['name']}\" y unidad \"{$data['unit']}\".",
            ], 422);
        }

        $material = MaterialCatalog::create([
            'name'                => $data['name'],
            'unit'                => $data['unit'],
            'estimated_unit_price' => $data['estimatedUnitPrice'] ?? 0,
            'is_active'           => $data['isActive'] ?? true,
        ]);

        return response()->json(new MaterialResource($material), 201);
    }

    public function show(MaterialCatalog $material)
    {
        return response()->json(new MaterialResource($material));
    }

    public function update(UpdateMaterialRequest $request, MaterialCatalog $material)
    {
        $data = $request->validated();

        if (isset($data['name'])) $data['name'] = strip_tags($data['name']);
        if (isset($data['unit'])) $data['unit'] = strip_tags($data['unit']);

        // Check unique (name, unit) conflict if name or unit changed
        if (isset($data['name']) || isset($data['unit'])) {
            $checkName  = $data['name'] ?? $material->name;
            $checkUnit  = $data['unit'] ?? $material->unit;

            $conflict = MaterialCatalog::where('name', $checkName)
                ->where('unit', $checkUnit)
                ->where('id', '!=', $material->id)
                ->exists();

            if ($conflict) {
                return response()->json([
                    'message' => "Ya existe otro material con el nombre \"{$checkName}\" y unidad \"{$checkUnit}\".",
                ], 422);
            }
        }

        $updateData = [];
        if (isset($data['name']))                $updateData['name'] = $data['name'];
        if (isset($data['unit']))                $updateData['unit'] = $data['unit'];
        if (isset($data['estimatedUnitPrice']))  $updateData['estimated_unit_price'] = $data['estimatedUnitPrice'];
        if (isset($data['isActive']))            $updateData['is_active'] = $data['isActive'];

        $material->update($updateData);

        return response()->json(new MaterialResource($material));
    }

    public function toggleStatus(MaterialCatalog $material)
    {
        $material->is_active = !$material->is_active;
        $material->save();

        return response()->json([
            'id'       => $material->id,
            'isActive' => $material->is_active,
        ]);
    }
}
