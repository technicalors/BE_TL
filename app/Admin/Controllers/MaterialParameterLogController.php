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
use Illuminate\Support\Facades\Log;

class MaterialParameterLogController extends Controller
{
    public function index(Request $request)
    {
        // $result = MachineParameterLogs::orderByDesc('created_at')->with('machineParameter.scenario')->get()->take(20);

        // $device_id = '22d821e0-45bd-11ef-b8c3-a13625245eca';
        // $result = LogWarningParameter::whereHas('machine', function ($query) use ($device_id) {
        //     $query->where('device_id', $device_id);
        // })->with(['machine', 'scenario'])->orderByDesc('updated_at')->first();

        // $monitor = Monitor::whereHas('machine', function ($q) use ($device_id) {
        //     $q->where('device_id', $device_id);
        // })->orderByDesc('updated_at')->first();

        $result = LogWarningParameter::where('parameter_id', 'PLC_AP01')->with(['machine', 'scenario'])->orderByDesc('updated_at')->first();
        $monitor = Monitor::where('parameter_id', 'PLC_AP01')->with('machine')->orderByDesc('updated_at')->first();

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
            Monitor::where('parameter_id', $request->parameter_id)->delete();
            LogWarningParameter::where('parameter_id', $request->parameter_id)->delete();
        }

        return response()->json([
            'success' => true,
            'data' => $monitor ?? null,
            'message' => 'Thao tác thành công',
        ], 200);
    }
}
