<?php

namespace App\Admin\Controllers;

use App\Events\ProductionUpdated;
use App\Models\InfoCongDoan;
use App\Models\IOTLog;
use App\Models\LogWarningParameter;
use App\Models\Lot;
use App\Models\Machine;
use App\Models\MachineIot;
use App\Models\MachineLog;
use App\Models\MachineParameterLogs;
use App\Models\MachineParameters;
use App\Models\MachineStatus;
use App\Models\ThongSoMay;
use App\Models\Tracking;
use Encore\Admin\Controllers\AdminController;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use stdClass;

class IOTController extends AdminController
{
    use API;

    public function updateQuantityFromIot(Request $request)
    {
        $iot_log = new IOTLog();
        $iot_log->data = $request->all();
        $iot_log->save();
        $machine = Machine::where('device_id', $request->device_id)->first();
        $status = MachineStatus::getStatus($machine->code);
        $info_cong_doan = InfoCongDoan::where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        $sl_bat = $info_cong_doan->product->so_bat ?? 1;
        $tracking = Tracking::getData($machine->code);
        $d_input = ($request->input - $tracking->input);
        $d_output = ($request->output - $tracking->output) * $sl_bat;
        if ($d_input < 0) $d_input = 0;
        if ($d_output < 0) $d_output = 0;
        if ($info_cong_doan) {
            $status = MachineStatus::getStatus($machine->code);
            if ($status == 0) { //chạy thử/vào hàng
                if (is_null($tracking->input) || $tracking->input == 0  || is_null($tracking->output) || $tracking->output == 0) {
                    $tracking->update(['input' => $request->input, 'output' => $request->output]);
                }
                if ($request->input > $tracking->input) {
                    $info_cong_doan->sl_dau_vao_chay_thu = ($request->input - $tracking->input);
                }
                if ($request->output > $tracking->output) {
                    $info_cong_doan->sl_dau_ra_chay_thu = ($request->output - $tracking->output) * $sl_bat;
                }
            } else if ($status == 1 || $status == 2) { // chạy hàng loạt
                if ($request->input > $tracking->input) {
                    $info_cong_doan->sl_dau_vao_hang_loat = ($request->input - $tracking->input);
                }
                if ($request->output > $tracking->output) {
                    if ($machine->code == 'IN_2_MAU_01') {
                        $info_cong_doan->sl_dau_ra_hang_loat = round(($request->output - $tracking->output) / 10);
                    } else {
                        $info_cong_doan->sl_dau_ra_hang_loat = ($request->output - $tracking->output) * $sl_bat;
                    }
                }
            }
            $info_cong_doan->save();
            $productionData = [
                'machine_code' => $machine->code,
                'lot_id' => $tracking->lot_id,
                'sl_dau_vao_hang_loat' => (int)$info_cong_doan->sl_dau_vao_hang_loat,
                'sl_dau_ra_hang_loat' => (int)$info_cong_doan->sl_dau_ra_hang_loat,
            ];
            broadcast(new ProductionUpdated($productionData));
        }
        return response()->json(['message' => 'Equipment quantity updated successfully'], 200);
    }

    public function updateStatusFromIot(Request $request)
    {
        $iot_log = new IOTLog();
        $iot_log->data = $request->all();
        $iot_log->save();
        $machine = Machine::where('device_id', $request->device_id)->first();
        $obj = new stdClass();
        $obj->status = $request->status;
        $obj->machine_id = $machine->code;
        $obj->type = 1;
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        $tracking->update(['status' => $request->status]);
        // if ($tracking->lot_id) {
        MachineLog::UpdateStatus($obj);
        // }
        return response()->json(['message' => 'Equipment status updated successfully'], 200);
    }

    public function updateParamsFromIot(Request $request)
    {
        $machine = Machine::where('device_id', $request->device_id)->first();
        $log_iot = new MachineIot();
        $log_iot->data = $request->all();
        $log_iot->save();
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        LogWarningParameter::checkParameter($request);
        if (!$tracking) {
            $tracking = new Tracking();
            $tracking->machine_id = $machine->code;
            $tracking->timestamp = strtotime(now());
            $tracking->save();
        }
        if (is_null($tracking->timestamp)) {
            $tracking->update(['timestamp' => strtotime(now())]);
        }
        if (!is_null($tracking->timestamp)) {
            if (strtotime(now()) >= ($tracking->timestamp +  300)) {
                $start = date('Y-m-d H:i:s', $tracking->timestamp);
                $end = date('Y-m-d H:i:s', $tracking->timestamp +  300);
                $machineIotQuery = MachineIot::where('data->device_id', $machine->device_id)->whereBetween('created_at', [$start, $end]);
                $logQuery = (clone $machineIotQuery);
                $logs = $logQuery->get()->pluck('data')->toArray();
                $parameters = MachineParameters::where('machine_id', $machine->code)->where('is_if', 1)->pluck('parameter_id')->toArray();
                $arr = [];
                foreach ($parameters as $key => $parameter) {
                    $arr[$parameter] = 0;
                    foreach ((array) $logs as $key => $log) {
                        if (isset($log[$parameter])) {
                            $arr[$parameter] = (float)$arr[$parameter] + (float)$log[$parameter];
                        }
                    }
                }
                $machineIotQuery->delete();
                Tracking::where('machine_id', $machine->code)->update(['timestamp' =>  strtotime(now())]);
                MachineParameterLogs::where('machine_id', $machine->code)->where('start_time', '<=', date('Y-m-d H:i:s',  strtotime(now())))->where('end_time', '>=', date('Y-m-d H:i:s',  strtotime(now())))->update(['data_if' => $arr]);
                if ($machine) {
                    $line = $machine->line;
                    $lot = Lot::find($tracking->lot_id);
                    $thong_so_may = new ThongSoMay();
                    $ca = (int)date('H',  strtotime(now()));
                    $thong_so_may['ngay_sx'] = date('Y-m-d H:i:s');
                    $thong_so_may['ca_sx'] = ($ca >= 7 && $ca <= 17) ? 1 : 2;
                    $thong_so_may['xuong'] = '';
                    $thong_so_may['line_id'] = $line->id;
                    $thong_so_may['lot_id'] = $lot ? $lot->id : null;
                    $thong_so_may['lo_sx'] = $lot ? $lot->lo_sx : null;
                    $thong_so_may['machine_code'] = $machine->code;
                    $thong_so_may['data_if'] = $arr;
                    $thong_so_may['date_if'] = date('Y-m-d H:i:s',  strtotime(now()));
                    $thong_so_may->save();
                }
            }
        }
        return response()->json(['message' => 'Equipment parameter log created successfully'], 200);
    }

    public function recordProductOutput(Request $request)
    {
        $machine = Machine::where('device_id', $request->device_id)->first();
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if (!$tracking) {
            return response()->json(['message' => 'Tracking not found'], 404);
        }
        $info_cong_doan = InfoCongDoan::where('line_id', $machine->line_id)->where('lot_id', $tracking->lot_id)->first();
        if ($info_cong_doan) {
            if (is_null($info_cong_doan['thoi_gian_bam_may'])) {
                $info_cong_doan['thoi_gian_bam_may'] = date('Y-m-d H:i:s');
                $info_cong_doan->save();
                $tracking->update(['input' => $request->input, 'output' => $request->output]);
            }
        } else {
            return response()->json(['message' => 'InfoCongDoan not found'], 404);
        }
        MachineStatus::active($machine->code);
        return $info_cong_doan;
    }
}
