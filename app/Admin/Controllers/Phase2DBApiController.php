<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Lot;
use App\Models\Machine;
use App\Models\MachineParameterLogs;
use App\Models\Shift;
use App\Models\Tracking;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Phase2DBApiController extends Controller
{
    use API;
    public function fmb(Request $request)
    {
        $lines = Line::where('factory_id', 2)->where('id', '<>', 29)->get();
        $data = [];
        foreach ($lines as $line) {
            $info = InfoCongDoan::where("line_id", $line->id)->with(["lot.plans", "lot.plan.product"])->orderBy('thoi_gian_bat_dau', 'DESC')->first();
            if (!$info) {
                $tm = [
                    "cong_doan" => mb_strtoupper($line->name, 'UTF-8'),
                    "product" => '',
                    "sl_dau_ra_kh" => $info->sl_kh ?? 0,
                    "sl_thuc_te" => 0,
                    "sl_muc_tieu" => 0,
                    "ti_le_ng" => 0,
                    "ti_le_ht" => 0,
                    "status" => 0,
                    "time" => "",
                    'ti_le_ht' => 0
                ];
                $data[] = $tm;
            } else {


                // $plan = $info->lot->getPlanByLine($info->line_id);
                $product = $info->product ?? null;
                // if (!isset($plan)) $plan = $info->lot->plan;
                $status = 0;
                if (!is_null($info->thoi_gian_bat_dau) && is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc)) {
                    $status = 1;
                }
                if (!is_null($info->thoi_gian_bat_dau) && !is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc)) {
                    $status = 2;
                }
                if (!is_null($info->thoi_gian_bat_dau) && !is_null($info->thoi_gian_bam_may) && !is_null($info->thoi_gian_ket_thuc)) {
                    $status = 3;
                }
                $tm = [
                    "cong_doan" => mb_strtoupper($info->line->name, 'UTF-8'),
                    "product" => $product ? $product->name : '',
                    "sl_dau_ra_kh" => $info->sl_kh ?? 0,
                    "sl_thuc_te" => $info->sl_dau_ra_hang_loat - $info->sl_ng,
                    "sl_muc_tieu" => $info->sl_kh,
                    "ti_le_ng" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ?  number_format(($info->sl_ng /  $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "ti_le_ht" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ? number_format((($info->sl_dau_ra_hang_loat - $info->sl_ng) / $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "status" => $status,
                    "time" => $info->updated_at,
                ];
                $tm['ti_le_ht'] = (int) (100 * (($tm['sl_dau_ra_kh']) > 0 ? number_format(($tm['sl_thuc_te'] / ($tm['sl_dau_ra_kh'])), 2) : 0));
                $data[] = $tm;
            }
        }
        return $this->success($data);
    }

    public function getMachinePerformance()
    {
        $lines = Line::where('factory_id', 2)->where('id', '<>', 29)->get();
        $res = [];
        foreach ($lines as $key => $line) {
            $machines = Machine::where('line_id', $line->id)->get();
            foreach ($machines as $machine) {
                $res[$machine->code]['machine_name'] = $machine->name;
                $tracking = Tracking::where('machine_id', $machine->code)->first();
                if ($machine->is_iot == 1) {
                    $res[$machine->code]['status'] = $tracking->status;
                } else {
                    $res[$machine->code]['status'] = is_null($tracking->lot_id) ? 0 : 1;
                }
                if (is_null($tracking->lot_id)) {
                    $res[$machine->code]['percent'] = 0;
                } else {
                    $lot = Lot::find($tracking->lot_id);
                    if (!$lot) {
                        $res[$machine->code]['percent'] = 0;
                        continue;
                    }
                    $plan = $lot->getPlanByLine($line->id);
                    $tg_kh = $plan ? strtotime($plan->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau) : 0;
                    $info_cds = InfoCongDoan::where('line_id', $line->id)
                        ->where('machine_code', $machine->code)
                        ->where('lot_id', 'like', '%' . $lot->lo_sx . '%')
                        ->orderBy('thoi_gian_bat_dau', 'DESC')
                        ->whereNotNull('thoi_gian_bat_dau')
                        ->whereNotNull('thoi_gian_bam_may')
                        ->whereNotNull('thoi_gian_ket_thuc')
                        ->get();
                    $tg_tsl = 0;
                    $tong_sl = 0;
                    $tong_sl_dat = 0;
                    $tong_tg = 0;
                    foreach ($info_cds as $info_cd) {
                        $tg_tsl += is_null($info_cd->thoi_gian_ket_thuc) ? strtotime(date('Y-m-d H:i:s')) - strtotime($info_cd->thoi_gian_bam_may) : strtotime($info_cd->thoi_gian_ket_thuc) - strtotime($info_cd->thoi_gian_bam_may);
                        $tong_tg += strtotime($info_cd->thoi_gian_ket_thuc) - strtotime($info_cd->thoi_gian_bat_dau);
                        $tong_sl += $info_cd->sl_dau_ra_hang_loat;
                        $tong_sl_dat += $info_cd->sl_dau_ra_hang_loat - $info_cd->sl_ng;
                    }
                    $A = $tong_tg > 0 ? ($tg_tsl / $tong_tg) * 100 : 0;
                    $Q = $tong_sl > 0 ? ($tong_sl_dat / $tong_sl) * 100 : 0;
                    $P = (isset($plan) && $plan->UPH && $tg_tsl > 0) ? ($tong_sl / (($tg_tsl / 3600) * (int)$plan->UPH)) * 100 : 0;
                    $res[$machine->code]['percent'] = (int)number_format(($A * $Q * $P) / 10000);
                    // $res[$machine->code]['percent'] += 40;
                }
            }
        }
        return $this->success($res);
    }

    public function handle()
    {
        try {
            $shifts = Shift::all();
            $machines = Machine::all();
            foreach ($shifts as $key => $shift) {
                $check = MachineParameterLogs::whereDate('start_time', date('Y-m-d'))->whereTime('start_time', '=', $shift->start_time)->first();
                // return [strtotime(date('H:i:s')) > (strtotime($shift->start_time) -  7200), strtotime(date('H:i:s')) < strtotime($shift->start_time), !$check];
                if (strtotime(date('H:i:s')) > (strtotime($shift->start_time) -  7200) && !$check) {
                    $start_time = date('Y-m-d H:i:s', strtotime($shift->start_time));
                    $end_time = strtotime($shift->start_time) > strtotime($shift->end_time) ? date('Y-m-d H:i:s', strtotime($shift->end_time . ' +1 day')) : date('Y-m-d H:i:s', strtotime($shift->end_time));
                    while (strtotime($start_time) < strtotime($end_time)) {
                        $end = date('Y-m-d H:i:s', strtotime($start_time) + 7200);
                        foreach ($machines as $key => $machine) {
                            MachineParameterLogs::create(['start_time' => $start_time, 'end_time' => $end, 'machine_id' => $machine->code]);
                        }
                        $start_time = $end;
                    }
                }
            }
        } catch (\Throwable $th) {
            throw $th;
        }
        return 'ok';
    }

    public function getProductionSituationLineIn(Request $request)
    {
        $machines = Machine::with('line')->where('line_id', 25)->orderBy('name')->get();
        $data = [];
        foreach ($machines as $machine) {
            $info = InfoCongDoan::where("line_id", $machine->line_id)->where('machine_code', $machine->code)->with(["lotPlan", "lot.plan.product"])->orderBy('thoi_gian_bat_dau', 'DESC')->first();
            if (!$info) {
                $tm = [
                    "cong_doan" => mb_strtoupper($machine->line->name, 'UTF-8'),
                    'machine_code' => mb_strtoupper($machine->code, 'UTF-8'),
                    'machine_name' => mb_strtoupper($machine->name, 'UTF-8'),
                    "product" => '',
                    "sl_dau_ra_kh" => $info->lotPlan->quantity ?? 0,
                    "sl_thuc_te" => 0,
                    "sl_muc_tieu" => 0,
                    "ti_le_ng" => 0,
                    "ti_le_ht" => 0,
                    "status" => 0,
                    "time" => "",
                    'ti_le_ht' => 0
                ];
                $data[] = $tm;
            } else {
                $lotPlan = $info->lotPlan;
                if (!$lotPlan) {
                    continue;
                }
                // $plan = $info->lot->getPlanByLine($info->line_id);
                $product = $info->product ?? null;
                // if (!isset($plan)) $plan = $info->lot->plan;
                $status = 0;
                if (!is_null($info->thoi_gian_bat_dau) && is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc)) {
                    $status = 1;
                }
                if (!is_null($info->thoi_gian_bat_dau) && !is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc)) {
                    $status = 2;
                }
                if (!is_null($info->thoi_gian_bat_dau) && !is_null($info->thoi_gian_bam_may) && !is_null($info->thoi_gian_ket_thuc)) {
                    $status = 3;
                }
                $upm = $lotPlan->quantity / (2 * 60);
                $diff_time = strtotime('now') - strtotime($info->thoi_gian_bat_dau ?? 'now');
                $target = (int)($upm * ($diff_time / 60));
                $tm = [
                    "cong_doan" => mb_strtoupper($info->line->name, 'UTF-8'),
                    'machine_code' => $machine->code,
                    'machine_name' => mb_strtoupper($machine->name, 'UTF-8'),
                    "product" => $product ? $product->name : '',
                    "sl_dau_ra_kh" => $lotPlan->quantity ?? 0,
                    "sl_thuc_te" => $info->sl_dau_ra_hang_loat - $info->sl_ng,
                    "sl_muc_tieu" => $target < $lotPlan->quantity ? $target : $lotPlan->quantity,
                    "ti_le_ng" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ?  number_format(($info->sl_ng /  $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "ti_le_ht" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ? number_format((($info->sl_dau_ra_hang_loat - $info->sl_ng) / $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "status" => $status,
                    "time" => $info->updated_at,
                ];
                $tm['ti_le_ht'] = (int) (100 * (($tm['sl_dau_ra_kh']) > 0 ? number_format(($tm['sl_thuc_te'] / ($tm['sl_dau_ra_kh'])), 2) : 0));
                $data[] = $tm;
            }
        }
        return $this->success($data);
    }

    public function getProductionSituationByMachine(Request $request)
    {
        $order = [];
        if (!empty($request->ordering_machine)) {
            $order = explode(',', $request->ordering_machine);
        }
        //reorder
        $firstElement = array_shift($order);
        array_push($order, $firstElement);
        $orderByString = "'" . implode("','", $order) . "'";

        $lines = Line::where('factory_id', 2)->where('id', '<>', 25)->get();
        $query = Machine::with('line')->whereIn('line_id', $lines->pluck('id')->where('is_iot', 1)->toArray());
        if (!empty($request->ordering_machine)) {
            $query->orderByRaw(DB::raw("FIELD(code, $orderByString)"));
        } else {
            $query->orderBy('name');
        }
        $machines = $query->get();
        $data = [];
        foreach ($machines as $machine) {
            $info = InfoCongDoan::where("line_id", $machine->line_id)->where('machine_code', $machine->code)->with(["lotPlan", "lot.plan.product"])->orderBy('thoi_gian_bat_dau', 'DESC')->first();
            if (!$info) {
                $tm = [
                    "cong_doan" => mb_strtoupper($machine->line->name, 'UTF-8'),
                    'machine_code' => $machine->code,
                    'machine_name' => mb_strtoupper($machine->name, 'UTF-8'),
                    "product" => '',
                    "sl_dau_ra_kh" => $info->lotPlan->quantity ?? 0,
                    "sl_thuc_te" => 0,
                    "sl_muc_tieu" => 0,
                    "ti_le_ng" => 0,
                    "ti_le_ht" => 0,
                    "status" => 0,
                    "time" => "",
                    'ti_le_ht' => 0
                ];
                $data[] = $tm;
            } else {
                $lotPlan = $info->lotPlan;
                if (!$lotPlan) {
                    continue;
                }
                // $plan = $info->lot->getPlanByLine($info->line_id);
                $product = $info->product ?? null;
                // if (!isset($plan)) $plan = $info->lot->plan;
                $status = 0;
                if (!is_null($info->thoi_gian_bat_dau) && is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc)) {
                    $status = 1;
                }
                if (!is_null($info->thoi_gian_bat_dau) && !is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc)) {
                    $status = 2;
                }
                if (!is_null($info->thoi_gian_bat_dau) && !is_null($info->thoi_gian_bam_may) && !is_null($info->thoi_gian_ket_thuc)) {
                    $status = 3;
                }
                $upm = $lotPlan->quantity / (2 * 60);
                $diff_time = strtotime('now') - strtotime($info->thoi_gian_bat_dau ?? 'now');
                $target = (int)($upm * ($diff_time / 60));
                $tm = [
                    'target' => $target,
                    "cong_doan" => mb_strtoupper($info->line->name, 'UTF-8'),
                    'machine_code' => $machine->code,
                    'machine_name' => mb_strtoupper($machine->name, 'UTF-8'),
                    "product" => $product ? $product->name : '',
                    "sl_dau_ra_kh" => $lotPlan->quantity ?? 0,
                    "sl_thuc_te" => $info->sl_dau_ra_hang_loat - $info->sl_ng,
                    "sl_muc_tieu" => $target < $lotPlan->quantity ? $target : $lotPlan->quantity,
                    "ti_le_ng" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ?  number_format(($info->sl_ng /  $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "ti_le_ht" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ? number_format((($info->sl_dau_ra_hang_loat - $info->sl_ng) / $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "status" => $status,
                    "time" => $info->updated_at,
                ];
                $tm['ti_le_ht'] = (int) (100 * (($tm['sl_dau_ra_kh']) > 0 ? number_format(($tm['sl_thuc_te'] / ($tm['sl_dau_ra_kh'])), 2) : 0));
                $data[] = $tm;
            }
        }
        return $this->success($data);
    }
}
