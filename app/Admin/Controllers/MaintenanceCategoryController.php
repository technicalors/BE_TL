<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceCategory;
use App\Traits\API;
use Illuminate\Http\Request;

class MaintenanceCategoryController extends Controller
{
    use API;
    public function index()
    {
        $data = MaintenanceCategory::with('maintenanceItems')->get();
        return $this->success($data);
    }

    public function show($id)
    {
        return MaintenanceCategory::find($id);
    }

    public function store(Request $request)
    {
        $maintenanceCategory = MaintenanceCategory::create($request->all());
        return response()->json($maintenanceCategory, 201);
    }

    public function update(Request $request, $id)
    {
        $maintenanceCategory = MaintenanceCategory::findOrFail($id);
        $maintenanceCategory->update($request->all());
        return response()->json($maintenanceCategory, 200);
    }

    public function destroy($id)
    {
        MaintenanceCategory::destroy($id);
        return response()->json(null, 204);
    }
}
