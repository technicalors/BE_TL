<?php

namespace App\Admin\Controllers;

use App\Models\InfoCongDoan;
use App\Models\LogWarningParameter;
use App\Models\Lot;
use App\Models\Machine;
use App\Models\MachineIOT;
use App\Models\MachineLog;
use App\Models\MachineParameterLogs;
use App\Models\MachineParameters;
use App\Models\MachineStatus;
use App\Models\ThongSoMay;
use App\Models\Tracking;
use Encore\Admin\Controllers\AdminController;
use Illuminate\Http\Request;
use App\Traits\API;

class IOTController extends AdminController
{
    use API;

    public function updateQuantityFromIot(Request $request)
    {
        $status = MachineStatus::getStatus($request->machine_id);
        $info_cong_doan = InfoCongDoan::where('machine_code', $request->machine_id)->where('status', 1)->first();
        $sl_bat = $info_cong_doan->lot->product->so_bat;
        $tracking = Tracking::getData($request->machine_id);
        $d_input = $request->input - $tracking->input;
        $d_output = $request->output - $tracking->output;
        // if ($machine->line_id != 13) {
        //     $d_output = $sl_bat * $d_output;
        //     $d_input = $sl_bat * $d_input;
        // }
        if ($d_input < 0) $d_input = 0;
        if ($d_output < 0) $d_output = 0;
        Tracking::updateData($request->machine_id, $request->input, $request->output);
        if ($info_cong_doan) {
            $status = MachineStatus::getStatus($request->machine_id);
            if ($status == 0) { //chạy thử/vào hàng
                if (!isset($info_cong_doan->sl_dau_vao_chay_thu)) $info_cong_doan->sl_dau_vao_chay_thu = 0;
                $info_cong_doan->sl_dau_vao_chay_thu += $d_input;

                if (!isset($info_cong_doan->sl_dau_ra_chay_thu)) $info_cong_doan->sl_dau_ra_chay_thu = 0;
                $info_cong_doan->sl_dau_ra_chay_thu += $d_output;
            } else if ($status == 1) { // chạy hàng loạt
                if (!isset($info_cong_doan->sl_dau_vao_hang_loat)) $info_cong_doan->sl_dau_vao_hang_loat = 0;
                $info_cong_doan->sl_dau_vao_hang_loat += $d_input;

                if (!isset($info_cong_doan->sl_dau_ra_hang_loat)) $info_cong_doan->sl_dau_ra_hang_loat = 0;
                $info_cong_doan->sl_dau_ra_hang_loat += $d_output;
            }
            $info_cong_doan->save();
        }
        return response()->json(['message' => 'Equipment quantity updated successfully'], 200);
    }

    public function updateStatusFromIot(Request $request)
    {
        $tracking = Tracking::where('machine_id', $request->machine_id)->first();
        $tracking->update(['status' => $request->status]);
        if ($tracking->lot_id) {
            MachineLog::UpdateStatus($request);
        }
        return response()->json(['message' => 'Equipment status updated successfully'], 200);
    }

    public function updateParamsFromIot(Request $request)
    {
        $log_iot = new MachineIOT();
        $log_iot->data = $request->all();
        $log_iot->save();
        $tracking = Tracking::where('machine_id', $request->machine_id)->first();
        LogWarningParameter::checkParameter($request);
        if (!$tracking) {
            $tracking = new Tracking();
            $tracking->machine_id = $request->machine_id;
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
                $logs = MachineIOT::where('data->record_type', "cl")->where('data->machine_id', $request->machine_id)->where('data->timestamp', '>=', $start)->where('data->timestamp', '<=', $end)->pluck('data')->toArray();
                $parameters = MachineParameters::where('machine_id', $request->machine_id)->where('is_if', 1)->pluck('parameter_id')->toArray();
                $arr = [];
                foreach ($parameters as $key => $parameter) {
                    $arr[$parameter] = 0;
                    foreach ((array) $logs as $key => $log) {
                        if (isset($log[$parameter])) {
                            $arr[$parameter] = (float)$arr[$parameter] + (float)$log[$parameter];
                        }
                    }
                }
                MachineIOT::where('data->record_type', "cl")->where('data->machine_id', $request->machine_id)->delete();
                Tracking::where('machine_id', $request->machine_id)->update(['timestamp' => $request->timestamp]);
                MachineParameterLogs::where('machine_id', $request->machine_id)->where('start_time', '<=', date('Y-m-d H:i:s', $request->timestamp))->where('end_time', '>=', date('Y-m-d H:i:s', $request->timestamp))->update(['data_if' => $arr]);
                $machine = Machine::where('code', $request->machine_id)->first();
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
}
