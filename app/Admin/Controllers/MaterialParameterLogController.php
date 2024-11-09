<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogWarningParameter;
use App\Models\MachineParameterLogs;
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
        $result = LogWarningParameter::orderByDesc('updated_at')->where('parameter_id', 'PLC_CB01')->with(['machine', 'scenario'])->first();
        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'success',
        ], 200);
    }
}
