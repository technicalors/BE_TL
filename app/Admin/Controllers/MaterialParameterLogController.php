<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogWarningParameter;
use App\Models\MachineParameterLogs;
use Encore\Admin\Controllers\Dashboard;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;

class MaterialParameterLogController extends Controller
{
    public function index()
    {
        // $result = MachineParameterLogs::orderByDesc('created_at')->with('machineParameter.scenario')->get()->take(20);
        $result = LogWarningParameter::orderByDesc('updated_at')->where('machine_id', 'e281ee2c-0b50-404b-9c5f-c082dc655d64')->first();
        return response()->json(['data' => $result], 200);
    }
}
