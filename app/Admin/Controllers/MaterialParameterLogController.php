<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogWarningParameter;
use App\Models\MachineParameterLogs;
use App\Models\Monitor;
use Encore\Admin\Controllers\Dashboard;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Illuminate\Http\Request;

class MaterialParameterLogController extends Controller
{
    public function index(Request $request)
    {
        // $result = MachineParameterLogs::orderByDesc('created_at')->with('machineParameter.scenario')->get()->take(20);
        $result = LogWarningParameter::where('parameter_id', 'PLC_CB01')->with(['machine', 'scenario'])->orderByDesc('updated_at')->first();
        $monitor = Monitor::where('parameter_id', 'PLC_CB01')->orderByDesc('updated_at')->first();
        $result = (object) ($result ?? []);
        $result->monitor = $monitor ?? null;
        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'success',
        ], 200);
    }

    public function update(Request $request)
    {
        $request->validate([
            'parameter_id' => 'required',
            'troubleshoot' => 'required',
        ]);

        $monitor = Monitor::where('parameter_id', $request->parameter_id)->orderByDesc('updated_at')->first();
        if (!empty($monitor)) {
            $monitor->troubleshoot = $request->troubleshoot;
            $monitor->save();
        }

        return response()->json([
            'success' => true,
            'data' => $monitor ?? null,
            'message' => 'Thao tác thành công',
        ], 200);
    }
}
