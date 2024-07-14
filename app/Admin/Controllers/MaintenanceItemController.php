<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceItem;
use App\Traits\API;
use Illuminate\Http\Request;

class MaintenanceItemController extends Controller
{
    use API;
    public function index()
    {
        $data = MaintenanceItem::all();
        return $this->success($data);
    }

    public function show($id)
    {
        return MaintenanceItem::find($id);
    }

    public function store(Request $request)
    {
        $maintenanceItem = MaintenanceItem::create($request->all());
        return response()->json($maintenanceItem, 201);
    }

    public function update(Request $request, $id)
    {
        $maintenanceItem = MaintenanceItem::findOrFail($id);
        $maintenanceItem->update($request->all());
        return response()->json($maintenanceItem, 200);
    }

    public function destroy($id)
    {
        MaintenanceItem::destroy($id);
        return response()->json(null, 204);
    }
}
