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
        $info_cong_doan = InfoCongDoan::where('machine_code', $machine->code)->where('status', 1)->first();
        // $sl_bat = $info_cong_doan->lot->product->so_bat;
        $tracking = Tracking::getData($machine->code);
        $d_input = $request->input - $tracking->input;
        $d_output = $request->output - $tracking->output;
        if ($d_input < 0) $d_input = 0;
        if ($d_output < 0) $d_output = 0;
        Tracking::updateData($machine->code, $request->input, $request->output);
        if ($info_cong_doan) {
            $status = MachineStatus::getStatus($machine->code);
            if ($status == 0) { //chạy thử/vào hàng
                if (!isset($info_cong_doan->sl_dau_vao_chay_thu)) $info_cong_doan->sl_dau_vao_chay_thu = 0;
                $info_cong_doan->sl_dau_vao_chay_thu += $d_input;

                if (!isset($info_cong_doan->sl_dau_ra_chay_thu)) $info_cong_doan->sl_dau_ra_chay_thu = 0;
                $info_cong_doan->sl_dau_ra_chay_thu += $d_output;
            } else if ($status == 1 || $status == 2) { // chạy hàng loạt
                if (!isset($info_cong_doan->thoi_gian_bam_may)) $info_cong_doan->thoi_gian_bam_may = Carbon::now();
                if (!isset($info_cong_doan->sl_dau_vao_hang_loat)) $info_cong_doan->sl_dau_vao_hang_loat = 0;
                $info_cong_doan->sl_dau_vao_hang_loat += $d_input;

                if (!isset($info_cong_doan->sl_dau_ra_hang_loat)) $info_cong_doan->sl_dau_ra_hang_loat = 0;
                $info_cong_doan->sl_dau_ra_hang_loat += $d_output;
            }
            $productionData = [
                'lot_id' => $tracking->lot_id,
                'sl_dau_vao_hang_loat' => (int)$info_cong_doan->sl_dau_vao_hang_loat,
                'sl_dau_ra_hang_loat' => (int)$info_cong_doan->sl_dau_ra_hang_loat,
            ];
            event(new ProductionUpdated($productionData));
            $info_cong_doan->save();
        }
        return response()->json(['message' => 'Equipment quantity updated successfully'], 200);
    }

    public function updateStatusFromIot(Request $request)
    {
        $iot_log = new IOTLog();
        $iot_log->data = $request->all();
        $iot_log->save();
        $machine = Machine::where('device_id', $request->device_id)->first();
        $obj = new stdClass($request);
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
            $tracking->timestamp = $request->timestamp;
            $tracking->save();
        }
        if (is_null($tracking->timestamp)) {
            $tracking->update(['timestamp' => $request->timestamp]);
        }
        if (!is_null($tracking->timestamp)) {
            if ($request->timestamp  >= ($tracking->timestamp +  300)) {
                $start = $tracking->timestamp;
                $end = $tracking->timestamp +  300;
                $logs = MachineIot::where('data->record_type', "cl")->where('data->machine_id', $machine->code)->where('data->timestamp', '>=', $start)->where('data->timestamp', '<=', $end)->pluck('data')->toArray();
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
                MachineIot::where('data->record_type', "cl")->where('data->machine_id', $machine->code)->delete();
                Tracking::where('machine_id', $machine->code)->update(['timestamp' => $request->timestamp]);
                MachineParameterLogs::where('machine_id', $machine->code)->where('start_time', '<=', date('Y-m-d H:i:s', $request->timestamp))->where('end_time', '>=', date('Y-m-d H:i:s', $request->timestamp))->update(['data_if' => $arr]);
                if ($machine) {
                    $line = $machine->line;
                    $updated_tracking = Tracking::where('machine_id', $machine->code)->first();
                    $lot = Lot::find($updated_tracking->lot_id);
                    $thong_so_may = new ThongSoMay();
                    $ca = (int)date('H', $request->timestamp);
                    $thong_so_may['ngay_sx'] = date('Y-m-d H:i:s');
                    $thong_so_may['ca_sx'] = ($ca >= 7 && $ca <= 17) ? 1 : 2;
                    $thong_so_may['xuong'] = '';
                    $thong_so_may['line_id'] = $line->id;
                    $thong_so_may['lot_id'] = $lot ? $lot->id : null;
                    $thong_so_may['lo_sx'] = $lot ? $lot->lo_sx : null;
                    $thong_so_may['machine_code'] = $machine->code;
                    $thong_so_may['data_if'] = $arr;
                    $thong_so_may['date_if'] = date('Y-m-d H:i:s', $request->timestamp);
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
            $info_cong_doan['thoi_gian_bam_may'] = date('Y-m-d H:i:s');
            $info_cong_doan->save();
        } else {
            return response()->json(['message' => 'InfoCongDoan not found'], 404);
        }
        MachineStatus::active($machine->code);
        return $info_cong_doan;
    }
}
