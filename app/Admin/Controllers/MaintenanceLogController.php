<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceLog;
use App\Traits\API;
use Illuminate\Http\Request;

class MaintenanceLogController extends Controller
{
    use API;
    public function index()
    {
        $data = MaintenanceLog::all();
        return $this->success($data);
    }

    public function show($id)
    {
        return MaintenanceLog::find($id);
    }

    public function store(Request $request)
    {
        $maintenanceLog = MaintenanceLog::create($request->all());
        return response()->json($maintenanceLog, 201);
    }

    public function update(Request $request, $id)
    {
        $maintenanceLog = MaintenanceLog::findOrFail($id);
        $maintenanceLog->update($request->all());
        return response()->json($maintenanceLog, 200);
    }

    public function destroy($id)
    {
        MaintenanceLog::destroy($id);
        return response()->json(null, 204);
    }
}
