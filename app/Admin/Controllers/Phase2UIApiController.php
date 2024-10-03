<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\InfoCongDoanImport;
use App\Imports\WarehouseLocationImport;
use App\Models\Customer;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\ErrorMachine;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Losx;
use App\Models\Lot;
use App\Models\LotPlan;
use App\Models\Machine;
use App\Models\MachineLog;
use App\Models\MachinePriorityOrder;
use App\Models\MachineShift;
use App\Models\NumberMachineOrder;
use App\Models\ProductionPlan;
use App\Models\ProductOrder;
use App\Models\QCHistory;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\Spec;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Phase2UIApiController extends Controller
{
    use API;
    public function getTreeSelect(Request $request)
    {
        $factories = Factory::with(
            [
                'line' => function ($query) {
                    $query->with('machine')->whereHas('machine');
                },
            ]
        )
            ->where('id', 2)
            ->get();
        foreach ($factories as $factory) {
            foreach ($factory->line as $line) {
                foreach ($line->machine as $machine) {
                    $machine->key = $machine->code;
                    $machine->title = $machine->code;
                    $machine->type = 'machine';
                }
                $line->key = $line->id;
                $line->title = $line->name;
                $line->children = $line->machine;
                $line->type = 'line';
            }
            $factory['key'] = $factory->id;
            $factory['title'] = $factory->name;
            $factory['children'] = $factory->line;
            $factory['type'] = 'factory';
        }
        return $this->success($factories);
    }

    private function productionOverall($infos)
    {
        $overall = [
            "sl_dau_ra_kh" => 0,
            "sl_dau_ra_thuc_te_ok" => 0,
            "sl_chenh_lech" => 0,
            "ty_le" => 0,
            "sl_tem_vang" => 0,
            "sl_ng" => 0,
        ];
        $sl_thuc_te = 0;
        foreach ($infos as $item) {
            if ($item->lot->type == 1) continue;
            $overall["sl_dau_ra_thuc_te_ok"] += $item->sl_dau_ra_hang_loat - ($item->sl_tem_vang + $item->sl_ng);
            $sl_thuc_te += $item->sl_dau_ra_hang_loat - $item->sl_ng;
            $overall["sl_tem_vang"] += $item->sl_tem_vang;
            $overall["sl_ng"] += $item->sl_ng;
            if ($item->plan) {
                $overall['sl_dau_ra_kh'] += ($item->plan->sl_thanh_pham && $item->plan->sl_thanh_pham) ? ($item->lot->product->so_bat * $item->plan->sl_thanh_pham) : $item->plan->sl_giao_sx;
            }
        }
        $overall["sl_chenh_lech"] = ($overall["sl_dau_ra_thuc_te_ok"] + $overall["sl_tem_vang"] + $overall["sl_ng"]) - $overall['sl_dau_ra_kh'];
        $overall["ty_le"] = ($overall['sl_dau_ra_kh'] ? (int)(($sl_thuc_te / $overall['sl_dau_ra_kh']) * 100) : 0) . '%';
        return $overall;
    }

    private function productionPercent($query)
    {
        $data = [];
        $line_ids = Line::where('factory_id', 2)->pluck('id')->toArray();
        $info_cds = $query->whereIn('line_id', $line_ids)->where('status', InfoCongDoan::STATUS_COMPLETED)->select('lo_sx', 'line_id', DB::raw("SUM(sl_dau_ra_hang_loat) - SUM(sl_ng) as sl_daura"))->groupBy('lo_sx', 'line_id')->get();
        foreach ($info_cds as $key => $info_cd) {
            $data[$info_cd->lo_sx][$info_cd->line_id] = $info_cd->sl_daura;
        }
        return $data;
    }

    private function productionTable($infos)
    {
        $data = [];
        $shift = Shift::first();
        $errors = Error::all();
        $err_arr = [];
        foreach ($errors as $key => $error) {
            $err_arr[$error->id] = $error->noi_dung;
        }
        $users = CustomUser::all();
        $user_arr = [];
        foreach ($users as $key => $user) {
            $user_arr[$user->id] = $user->name;
        }
        foreach ($infos as $item) {
            if ($item->type == 'qc') continue;
            if (!$item->lot) continue;

            $start = new Carbon($item->thoi_gian_bat_dau);
            $end = new Carbon($item->thoi_gian_ket_thuc);
            $d = $end->diffInMinutes($start);

            $start_date = date("Y/m/d", strtotime($start));
            $start_shift = strtotime($start_date . ' ' . $shift->start_time);
            $end_shift = strtotime($start_date . ' ' . $shift->end_time);
            if (strtotime($start) >= $start_shift && strtotime($start) <=  $end_shift) {
                $ca_sx = 'Ca 1';
            } else {
                $ca_sx = 'Ca 2';
            }

            $info = $item->lot->log ? $item->lot->log->info : [];
            $line_key = Str::slug($item->line->name);
            $errors = [];
            $thoi_gian_kiem_tra = '';
            $sl_ng_pqc = 0;
            $sl_ng_sxkt = 0;
            $user_pqc = '';
            $user_sxkt = '';
            if (isset($info['qc']) && isset($info['qc'][$line_key])) {
                $info_qc = $info['qc'][$line_key];
                if ($line_key === 'gap-dan' && isset($info_qc['bat'])) {
                    $qc_error = [];
                    foreach ($info_qc['bat'] as $bat_error) {
                        if (isset($bat_error['errors'])) {
                            $qc_error = array_merge($qc_error, $bat_error['errors']);
                        }
                    }
                } else {
                    $qc_error = $info_qc['errors'] ?? [];
                }
                foreach ($qc_error as $key => $err) {
                    if (!is_numeric($err)) {
                        foreach ($err['data'] ?? [] as $err_key => $err_val) {
                            if (isset($err['type']) && $err['type'] === 'qc') {
                                if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                                    $sl_ng_pqc += $err_val;
                                } else {
                                    $sl_ng_pqc += $err_val * $item->lot->product->so_bat;
                                }
                                $user_pqc = $user_arr[$err['user_id']] ? $user_arr[$err['user_id']] : '';
                            } else {
                                if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                                    $sl_ng_sxkt += $err_val;
                                } else {
                                    $sl_ng_sxkt += $err_val * $item->lot->product->so_bat;
                                }
                                $user_sxkt = $user_arr[$err['user_id']] ? $user_arr[$err['user_id']] : '';
                            }
                            if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                                $errors[$err_key]['value'] = ($errors[$err_key]['value'] ?? 0) + $err_val;;
                            } else {
                                $errors[$err_key]['value'] = ($errors[$err_key]['value'] ?? 0) + $err_val * $item->lot->product->so_bat;
                            }
                            $errors[$err_key]['name'] = $err_arr[$err_key];
                        }
                    } else {
                        $sl_ng_pqc += $err;
                        $errors[$key]['value'] = ($errors[$key]['value'] ?? 0) + $err;
                        $errors[$key]['name'] = $err_arr[$key];
                    }
                }
                $user_sxkt = isset($info[$line_key]['user_name']) ? $info[$line_key]['user_name'] : '';
                $user_pqc = isset($info_qc['user_name']) ? $info_qc['user_name'] : '';
            }
            $tm = [
                "ngay_sx" => date('d/m/Y H:i:s', strtotime($item->created_at)),
                'ca_sx' => $ca_sx,
                'xuong' => 'Giấy',
                "cong_doan" => $item->line->name,
                "machine" => count($item->line->machine) ? $item->line->machine[0]->name : '-',
                "machine_id" => count($item->line->machine) ? $item->line->machine[0]->code : '-',
                "khach_hang" => $item->plan ? $item->plan->khach_hang : '',
                "ten_san_pham" => $item->lot->product ? $item->lot->product->name : '',
                "product_id" => $item->lot->product ? $item->lot->product->id : '',
                "material_id" => $item->lot->product ? $item->lot->product->material_id : '',
                "lo_sx" => $item->lot->lo_sx,
                "lot_id" => $item->lot_id,
                "thoi_gian_bat_dau_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : '',
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : '',
                "sl_dau_vao_kh" => $item->plan ? ($item->plan->sl_nvl ? $item->plan->sl_nvl : $item->plan->sl_giao_sx) : 0,
                "sl_dau_ra_kh" => $item->plan ? ($item->plan->sl_thanh_pham ? $item->plan->sl_thanh_pham : $item->plan->sl_giao_sx) : 0,
                "thoi_gian_bat_dau" => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : '-',
                "thoi_gian_bam_may" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "thoi_gian_ket_thuc" => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : '-',
                "thoi_gian_chay_san_luong" =>  number_format($d / 60, 2),
                "sl_ng" => $sl_ng_pqc + $sl_ng_sxkt,
                "sl_tem_vang" => $item->sl_tem_vang,
                "sl_dau_ra_ok" => $item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang,
                "ti_le_ng" => number_format($item->sl_dau_ra_hang_loat > 0 ? ($item->sl_ng / $item->sl_dau_ra_hang_loat) : 0, 2) * 100,
                "sl_dau_ra_hang_loat" => $item->sl_dau_ra_hang_loat,
                "sl_dau_vao_hang_loat" => $item->sl_dau_vao_hang_loat,
                "sl_dau_ra_chay_thu" => $item->sl_dau_ra_chay_thu ? $item->sl_dau_ra_chay_thu : '-',
                "sl_dau_vao_chay_thu" => $item->sl_dau_vao_chay_thu ? $item->sl_dau_vao_chay_thu : '-',
                "ty_le_dat" => $item->sl_dau_ra_hang_loat > 0 ? number_format(($item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang) / $item->sl_dau_ra_hang_loat) : '-',
                "cong_nhan_sx" =>  $item->plan ? $item->plan->nhan_luc : "-",
                "leadtime" => $item->thoi_gian_ket_thuc ? number_format((strtotime($item->thoi_gian_ket_thuc) - strtotime($item->thoi_gian_bat_dau)) / 3600, 2) : '-',
                "tt_thuc_te" => ($item->sl_dau_ra_hang_loat > 0 && $item->thoi_gian_bam_may) ? number_format((strtotime($item->thoi_gian_ket_thuc) - strtotime($item->thoi_gian_bam_may)) / ($item->sl_dau_ra_hang_loat * 60), 4) : '-',
                "chenh_lech" => $item->sl_dau_vao_hang_loat - $item->sl_dau_ra_hang_loat,
                "errors" => $errors,
                'thoi_gian_kiem_tra' => $thoi_gian_kiem_tra,
                'sl_ng_pqc' => $sl_ng_pqc,
                'sl_ng_sxkt' => $sl_ng_sxkt,
                'user_pqc' => $user_pqc,
                'user_sxkt' => $user_sxkt,
                'dien_nang' => $item->powerM ? number_format($item->powerM) : '',
            ];
            $data[] = $tm;
        }
        return $data;
    }

    public function productionHistoryQuery(Request $request)
    {
        $line_ids = Line::where('factory_id', 2)->pluck('id')->toArray();
        $query = InfoCongDoan::whereIn('line_id', $line_ids)->whereNotNull('thoi_gian_bat_dau')->with("lot.plans", "lot.log", "lot.product", "line", "plan");
        if (isset($request->line_id)) {
            if (is_array($request->line_id)) {
                $query->whereIn('line_id', $request->line_id);
            } else {
                $query->where('line_id', $request->line_id);
            }
        }
        if (isset($request->date) && count($request->date)) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        } else {
            $query->whereDate('created_at', date('Y-m-d'));
        }
        if (isset($request->product_id)) {
            $query->where('lot_id', 'like',  '%' . $request->product_id . '%');
        }
        if (isset($request->ten_sp)) {
            $query->where('lot_id', 'like',  '%' . $request->ten_sp . '%');
        }
        if (isset($request->khach_hang)) {
            $khach_hang = Customer::where('id', $request->khach_hang)->first();
            if ($khach_hang) {
                $plan = ProductionPlan::where('khach_hang', $khach_hang->name)->get();
                $product_ids = $plan->pluck('product_id')->toArray();
                $query->where(function ($qr) use ($product_ids) {
                    for ($i = 0; $i < count($product_ids); $i++) {
                        $qr->orwhere('lot_id', 'like',  '%' . $product_ids[$i] . '%');
                    }
                });
            }
        }
        if (isset($request->lo_sx)) {
            $lot = Lot::where('lo_sx', $request->lo_sx)->get();
            $query->whereIn('lot_id', $lot->pluck('id'));
        }
        return $query;
    }

    public function getProductionHistory(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->productionHistoryQuery($request);
        $percent_query = clone $query;
        $infos = $query->get();
        $info_table = $query->offset($page * $pageSize)->limit($pageSize)->get();
        $records = [];
        $lo_sx_ids = [];
        foreach ($infos as $key => $info) {
            if ($info->lot) {
                $records[] = $info;
                if (!in_array($info->lot->lo_sx, $lo_sx_ids)) {
                    $lo_sx_ids[] = $info->lot->lo_sx;
                }
            }
        }
        $overall = $this->productionOverall($records);
        $percent = $this->productionPercent($percent_query);
        $table = $this->productionTable($info_table);
        $count = count($infos);
        $totalPage = $count;
        return $this->success([
            "overall" => $overall,
            "percent" => $percent,
            "table" => $table,
            "totalPage" => $totalPage,
        ]);
    }

    public function uploadInfoCongDoan(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        DB::beginTransaction();
        try {
            Excel::import(new InfoCongDoanImport, $request->file('file'));
            DB::commit();
            return $this->success('', 'Upload thành công');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }

    /**
     * Upload vi tri kho
     */
    public function uploadWarehouseLocation(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        DB::beginTransaction();
        try {
            Excel::import(new WarehouseLocationImport, $request->file('file'));
            DB::commit();
            return $this->success('', 'Upload thành công');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }

    //Lấy dữ liệu biểu đồ oee
    public function getOEEData(Request $request)
    {
        $query = Line::where('factory_id', 2)->whereNotIn('id', [29, 30]);
        if (isset($request->line_id)) {
            $query->where('id', $request->line_id);
        }
        $lines = $query->get();
        $res = [];
        foreach ($lines as $key => $line) {
            $info_cds = InfoCongDoan::with('plan')
                ->where('line_id', $line->id)
                ->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0] ?? 'now')))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1] ?? 'now')))
                ->orderBy('thoi_gian_bat_dau', 'DESC')
                ->whereNotNull('thoi_gian_bat_dau')
                ->whereNotNull('thoi_gian_bam_may')
                ->whereNotNull('thoi_gian_ket_thuc')
                ->get();
            $tong_tg = 0;
            $tg_tsl = 0;
            $tong_sl = 0;
            $tong_sl_dat = 0;
            $uph = 0;
            $A = 0;
            $P = 0;
            $Q = 0;
            foreach ($info_cds as $info) {
                $plan = $info->plan;
                $tg_tsl += strtotime($info->thoi_gian_ket_thuc) - strtotime($info->thoi_gian_bam_may);
                $tong_tg += strtotime($info->thoi_gian_ket_thuc) - strtotime($info->thoi_gian_bat_dau);
                $tong_sl += $info->sl_dau_ra_hang_loat;
                $tong_sl_dat += $info->sl_dau_ra_hang_loat - $info->sl_ng;
                $uph += $plan ? $plan->UPH : 0;
            }
            $A = $tong_tg > 0 ? ($tg_tsl / $tong_tg) * 100 : 0;
            $Q = $tong_sl > 0 ? ($tong_sl_dat / $tong_sl) * 100 : 0;
            $P = ($uph && $tg_tsl >= 0) ? ($tong_sl / ($tg_tsl / 3600) / ($uph / count($info_cds))) * 100 : 0;
            $OEE = (int)round(($A * $Q * $P) / 10000);
            $res[] = ['line' => $line->name, 'A' => $A, 'Q' => $Q, 'P' => $P, 'OEE' => $OEE];
        }
        return $this->success($res);
    }

    //Lấy dữ liệu biểu đồ tần suất lỗi máy
    public function getErrorFrequencyData(Request $request)
    {
        $query = MachineLog::with("machine")->whereNotNull('info->error_id');
        if (isset($request->machine_code)) {
            $query->where('machine_id', $request->machine_code);
        }
        if (isset($request->line_id)) {
            $machine_codes = Machine::where('line_id', $request->line_id)->pluck('code')->toArray();
            $query->whereIn('machine_id', $machine_codes);
        }
        if (isset($request->lo_sx)) {
            $query->where('info->lo_sx', $request->lo_sx);
        }
        if (isset($request->user_id)) {
            $query->where('info->user_id', $request->user_id);
        }
        if (isset($request->machine_error)) {
            $query->where('info->error_id', $request->machine_error);
        }
        $mc_logs = [];
        $machine_logs = $query->get();
        foreach ($machine_logs as $key => $value) {
            if (($value->info['end_time'] - $value->info['start_time']) > 180) {
                $mc_logs[] = $value;
            }
        }
        $machine_error = ErrorMachine::all();
        $mark_err = [];
        foreach ($machine_error as $err) {
            $mark_err[$err->id] = $err;
        }
        $cnt_err = [];
        foreach ($machine_logs as $log) {
            if (isset($log->info['error_id'])) {
                if (!isset($cnt_err[$log->info['error_id']])) {
                    $cnt_err[$log->info['error_id']] = [
                        "name" => $mark_err[$log->info['error_id']]['code'],
                        "y" => 0,
                    ];
                }
                $cnt_err[$log->info['error_id']]["y"]++;
            }
        }
        return $this->success(array_values($cnt_err));
    }

    //QC

    //Pre Query QC History
    public function pqcHistoryQuery(Request $request)
    {
        $line_ids = Line::query()->pluck('id')->toArray();
        $query = QCHistory::orderBy('created_at');
        if (isset($request->date) && count($request->date) == 2) {
            $query->whereDate('scanned_time', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('scanned_time', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        $query->whereHas('infoCongDoan', function ($query) use ($request) {
            if (isset($request->line_id)) {
                if (is_array($request->line_id)) {
                    $query->whereIn('line_id', $request->line_id);
                } else {
                    $query->where('line_id', $request->line_id);
                }
            }
            if (isset($request->product_id)) {
                $query->where('lot_id', 'like',  '%' . $request->product_id . '%');
            }
            if (isset($request->ten_sp)) {
                $query->where('lot_id', 'like',  '%' . $request->ten_sp . '%');
            }
            if (isset($request->khach_hang)) {
                $khach_hang = Customer::where('id', $request->khach_hang)->first();
                if ($khach_hang) {
                    $plan = ProductionPlan::where('khach_hang', $khach_hang->name)->get();
                    $product_ids = $plan->pluck('product_id')->toArray();
                    $query->where(function ($qr) use ($product_ids) {
                        for ($i = 0; $i < count($product_ids); $i++) {
                            $qr->orwhere('lot_id', 'like',  '%' . $product_ids[$i] . '%');
                        }
                    });
                }
            }
            if (isset($request->lo_sx)) {
                $query->where('lot_id', 'like', "%$request->lo_sx%");
            }
        });

        $query->with('infoCongDoan.product', 'infoCongDoan.line', 'infoCongDoan.machine', 'user', 'errorHistories');
        return $query;
    }

    public function parseQCData($qc_histories)
    {
        $record = [];
        $shifts = Shift::all();
        foreach ($qc_histories as $key => $qc_history) {
            if (!$qc_history->infoCongDoan) {
                continue;
            }
            $ca_sx = $shifts->first(function ($shift) use ($qc_history) {
                $createdTime = Carbon::parse($qc_history->created_at)->format('H:i:s');
                return ($shift->start_time < $shift->end_time && $createdTime >= $shift->start_time && $createdTime <= $shift->end_time) ||
                    ($shift->start_time > $shift->end_time && ($createdTime >= $shift->start_time || $createdTime <= $shift->end_time));
            })->name ?? "";

            $user_sx = CustomUser::find($qc_history->infoCongDoan->user_id ?? null);
            $user_qc = $qc_history->user;
            $sl_ng_sx = 0;
            $sl_ng_qc = 0;
            if (count($qc_history->error_histories ?? [])) {
                foreach (($qc_history->error_histories ?? []) as $error) {
                    if ($error->type === 'sx') {
                        $sl_ng_sx += $error->quantity;
                    } else {
                        $sl_ng_qc += $error->quantity;
                    }
                }
            }
            $item = [
                'lot_id' => $qc_history->lot_id,
                'thoi_gian_kiem_tra' => Carbon::parse($qc_history->created_at)->format('d/m/Y H:i:s'),
                'ca_sx' => $ca_sx,
                'xuong' => $qc_history->line->factory->name ?? "Giấy",
                'cong_doan' => $qc_history->infoCongDoan->line->name ?? '',
                'machine' => $qc_history->infoCongDoan->machine->name ?? '',
                'machine_id' => $qc_history->infoCongDoan->machine_code ?? '',
                'khach_hang' => $qc_history->infoCongDoan->plan->khach_hang ?? "",
                'product_id' => $qc_history->infoCongDoan->product_id ?? '',
                'ten_san_pham' => $qc_history->infoCongDoan->product->name ?? "",
                'lo_sx' => $qc_history->infoCongDoan->lo_sx,
                'lot_id' => $qc_history->infoCongDoan->lot_id,
                'sl_dau_ra_hang_loat' => $qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                'sl_dau_ra_ok' => ($qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0) - ($qc_history->infoCongDoan->sl_tem_vang ?? 0) - ($qc_history->infoCongDoan->sl_ng ?? 0),
                'sl_tem_vang' => $qc_history->infoCongDoan->sl_tem_vang ?? 0,
                'sl_ng_sxkt' => $sl_ng_sx,
                'sl_ng_pqc' => $sl_ng_qc,
                'user_sxkt' => $user_sx->name ?? "",
                'user_pqc' => $user_qc->name ?? "",
                'sl_ng' => $qc_history->infoCongDoan->sl_ng ?? 0,
                'ti_le_ng' => (isset($qc_history->infoCongDoan->sl_dau_ra_hang_loat) && $qc_history->infoCongDoan->sl_dau_ra_hang_loat > 0) ? number_format(($qc_history->infoCongDoan->sl_ng / $qc_history->infoCongDoan->sl_dau_ra_hang_loat) * 100) . "%" : "0%",
            ];
            $record[] = $item;
        }
        return $record;
    }

    //Danh sách lot PQC
    public function getQualityDataTable(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->pqcHistoryQuery($request);
        $totalPage = $query->count();
        $records = $query->offset($page * $pageSize)->limit($pageSize)->get();
        $data = $this->parseQCData($records);
        return $this->success([
            "data" => $data,
            "totalPage" => $totalPage,
        ]);
    }

    public function parseErrorTrendingData($qcHistories)
    {
        $qcHistories;
        $data = [];
        foreach ($qcHistories as $qc_history) {
            $date = date('d/m', strtotime($qc_history->scanned_time));
            if (count($qc_history->errorHistories ?? []) > 0) {
                foreach (($qc_history->errorHistories ?? []) as $error) {
                    if (!isset($data[$error->error_id . $date])) {
                        $data[$error->error_id . $date] = [
                            'error' => $error->error_id,
                            'date' => $date,
                            'value' => 0
                        ];
                    }
                    $data[$error->error_id . $date]['value'] += $error->quantity;
                }
            }
        }
        return $data;
    }

    public function parseMaterialErrorRatioData($qcHistories)
    {
        $data = [];
        foreach ($qcHistories as $qc_history) {
            $date = date('d/m', strtotime($qc_history->created_at));
            if (count($qc_history->errorHistories ?? []) > 0) {
                foreach (($qc_history->errorHistories ?? []) as $error) {
                    if (str_contains($error->error_id, 'NVL')) {
                        if (!isset($data[$error->error_id . $date])) {
                            $data[$error->error_id . $date] = [
                                'error' => $error->error_id,
                                'date' => $date,
                                'value' => 0
                            ];
                        }
                        $data[$error->error_id . $date]['value'] += $error->quantity;
                    }
                }
            }
        }
        return $data;
    }

    public function parseErrorRatioData($qcHistories)
    {
        $data = [];
        foreach ($qcHistories as $qc_history) {
            if (count($qc_history->errorHistories ?? []) > 0) {
                foreach (($qc_history->errorHistories ?? []) as $error) {
                    if (!isset($data[$error->error_id])) {
                        $data[$error->error_id] = [
                            'name' => $error->error_id,
                            'frequency' => 0,
                            'value' => 0
                        ];
                    }
                    $sl_ng = $error->quantity ?? 0;
                    $sl_dau_vao_hang_loat = $qc_history->infoCongDoan->sl_dau_vao_hang_loat ?? 0;
                    $data[$error->error_id]['value'] += $sl_dau_vao_hang_loat ? $sl_ng / $sl_dau_vao_hang_loat * 100 : 0;
                    $data[$error->error_id]['frequency'] += 1;
                }
            }
        }
        return $data;
    }

    public function getQualityDataChart(Request $request)
    {
        $query = $this->pqcHistoryQuery($request);
        $qcHistories = $query->get();
        $data = new stdClass;
        $errorTrending = $this->parseErrorTrendingData($qcHistories);
        $materialErrorRatio = $this->parseMaterialErrorRatioData($qcHistories);
        $errorRatio = $this->parseErrorRatioData($qcHistories);
        $data->errorTrending = array_values($errorTrending);
        $data->materialErrorRatioData = array_values($materialErrorRatio);
        $data->errorRatioData = array_values($errorRatio);
        return $this->success($data);
    }
    public function getProductionSteps($productId)
    {
        // Bước 1: Truy vấn để lấy các công đoạn từ bảng spec theo product_id và slug 'hanh-trinh-san-xuat'
        // Sắp xếp theo thứ tự giảm dần (DESC) để tính toán sản lượng
        return Spec::where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            ->orderBy('value', 'desc')
            ->get();
    }

    function getOrderedProductionSteps($productId)
    {
        // Bước 8: Truy vấn để lấy các công đoạn từ bảng spec theo product_id và sắp xếp theo value ASC
        return Spec::where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            ->orderBy('value', 'asc')
            ->get()->filter(function ($value) {
                return is_numeric($value->value);
            })->values();
    }

    function calculateProductionOutput($productId, $lineId, $quantity)
    {
        // Bước 2: Lấy hao phí sản xuất theo % từ bảng spec
        $productionWaste = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'hao-phi-san-xuat-cac-cong-doan')
            ->first();

        // Lấy hao phí vào hàng từ bảng spec
        $inputWaste = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'hao-phi-vao-hang-cac-cong-doan')
            ->first();

        // Tính toán sản lượng sau khi tính thêm hao phí
        if ($productionWaste) {
            $quantity += $quantity * ($productionWaste->value / 100); // Thêm hao phí sản xuất
        }

        if ($inputWaste) {
            $quantity += $inputWaste->value; // Thêm hao phí vào hàng
        }

        return $quantity;
    }

    function getTransportTimeBetweenSteps($productId, $lineId)
    {
        // Truy vấn để lấy thời gian vận chuyển giữa các công đoạn từ bảng spec theo slug 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau'
        $transportTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau')
            ->first();

        return $transportTimeSpec ? $transportTimeSpec->value : 0; // Nếu không tìm thấy, trả về 0
    }

    function getLotSize($productId, $lineId)
    {
        // Truy vấn để lấy giá trị lotsize từ bảng spec theo slug 'so-luong'
        $lotSizeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'so-luong')
            ->first();

        return $lotSizeSpec ? $lotSizeSpec->value : 11000; // Nếu không tìm thấy, trả về 0
    }

    function getRollChangeTime($productId, $lineId)
    {
        // Truy vấn để lấy giá trị thời gian lên xuống cuộn từ bảng spec theo slug 'thoi-gian-len-xuong-cuon'

        $rollChangeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'thoi-gian-len-xuong-cuon')
            ->first();

        return $rollChangeSpec ? $rollChangeSpec->value : 0;
    }

    function getEfficiency($productId, $lineId)
    {
        // Truy vấn để lấy giá trị năng suất từ bảng spec theo slug 'nang-suat'
        $efficiencySpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'nang-suat-an-dinhgio')
            ->first();

        return $efficiencySpec ? $efficiencySpec->value : 0;
    }

    function getRollsPerTransport($productId, $lineId)
    {
        // Truy vấn để lấy số lượng cuộn một lần vận chuyển từ bảng spec theo slug 'so-luong-cuon-1-lan-van-chuyen'
        $rollsPerTransportSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'so-luong-cuon-1-lan-van-chuyen-cuon')
            ->first();

        return $rollsPerTransportSpec ? $rollsPerTransportSpec->value : 0;
    }

    function getSetupTime($productId, $lineId)
    {
        // Truy vấn để lấy giá trị thời gian vào hàng từ bảng spec theo slug 'vao-hang-setup-may'
        $setupTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'vao-hang-setup-may')
            ->first();

        return $setupTimeSpec ? $setupTimeSpec->value : 0; // Nếu không tìm thấy, trả về 0
    }

    function getMachineReady($lineId, $numMachines, $productId, $machine_available_list, $startTime)
    {
        //Truy vấn thứ tự ưu tiên máy
        $machinePriorityOrder = MachinePriorityOrder::where('product_id', $productId)->where('line_id', $lineId)->orderBy('priority')->pluck('priority', 'machine_id')->toArray();
        // Truy vấn bảng machine để lấy máy có available_at nhỏ nhất theo line_id
        if($lineId == 29){
            $machines = Machine::select('code', 'available_at', 'line_id')->where('line_id', $lineId)->get();
        }else{
            $machines = Machine::select('code', 'available_at', 'line_id')->where('line_id', $lineId)->whereIn('code', array_keys($machinePriorityOrder))->get();
        }
        foreach ($machines as $key => $machine) {
            if (isset($machine_available_list[$machine->code])) {
                $machine->available_at = $machine_available_list[$machine->code];
            }
            if (isset($machinePriorityOrder[$machine->code])) {
                $machine->priority = $machinePriorityOrder[$machine->code];
            }
        }
        [$beforeStart, $afterStart] = $machines->partition(function ($machine) use ($startTime) {
            $readyTime = Carbon::parse($machine->available_at);
            return $readyTime->lessThan($startTime) || $machine->available_at === null;
        });
        // Nếu có máy nào sẵn sàng trước thời gian bắt đầu, chọn máy có thứ tự ưu tiên cao nhất
        if (count($beforeStart) > 0) {
            $beforeStart = $beforeStart->sortBy('priority');
        }
        // Nếu không có máy nào trước thời gian bắt đầu, chọn máy có thời gian gần nhất với thời gian bắt đầu và ưu tiên theo thứ tự
        if (count($afterStart) > 0) {
            $afterStart = $afterStart->sort(function ($a, $b) use ($startTime) {
                // Kiểm tra sự tồn tại của 'priority' trong từng máy
                // $priorityA = isset($a->priority) ? $a->priority : PHP_INT_MAX; // Nếu không có 'priority', gán giá trị rất lớn
                // $priorityB = isset($b->priority) ? $b->priority : PHP_INT_MAX; // Nếu không có 'priority', gán giá trị rất lớn
                // // So sánh theo thứ tự ưu tiên trước
                // if ($priorityA == $priorityB) {
                //     // Nếu thứ tự ưu tiên bằng nhau hoặc không có 'priority', so sánh thời gian gần với $startTime
                $readyTimeA = Carbon::parse($a->available_at);
                $readyTimeB = Carbon::parse($b->available_at);
                //Nếu thời gian ss của của máy bằng nhau
                if ($readyTimeA->equalTo($readyTimeB)) {
                    // Kiểm tra sự tồn tại của 'priority' trong từng máy
                    $priorityA = isset($a->priority) ? $a->priority : PHP_INT_MAX; // Nếu không có 'priority', gán giá trị rất lớn
                    $priorityB = isset($b->priority) ? $b->priority : PHP_INT_MAX; // Nếu không có 'priority', gán giá trị rất lớn
                    // So sánh theo thứ tự ưu tiên
                    return $priorityA - $priorityB;
                }
                //Nếu không có máy sẵn sàng trước thời gian sx, thì lấy máy có thời gian sẵn sàng sớm nhất
                return abs($startTime->diffInMinutes($readyTimeA)) - abs($startTime->diffInMinutes($readyTimeB));
                // }
                // return $a->priority - $b->priority; // Thứ tự ưu tiên
            });
        }
        Log::info(['thoi gian bat dau', $startTime->format('Y-m-d H:i:s')]);
        Log::debug('before', $beforeStart->toArray());
        Log::debug('after', $afterStart->toArray());
        Log::info($numMachines);
        Log::debug($beforeStart->concat($afterStart));
        return $beforeStart->concat($afterStart)->take($numMachines)->values();
    }

    function getMachineProductionShifts($machineId, $date): Collection
    {
        $cacheKey = "machine_{$machineId}_production_shifts_{$date}";

        return Cache::remember($cacheKey, 60, function () use ($machineId, $date) {
            // Lấy tất cả các shift_id của máy trong ngày
            $shiftIds = DB::table('machine_shift')
                ->where('machine_id', $machineId)
                ->where('date', $date)
                ->pluck('shift_id');

            if ($shiftIds->isEmpty()) {
                return collect();
            }

            // Lấy tất cả các shift_breaks có type_break là 'Sản xuất' cho các shift_id
            return DB::table('shift_breaks')
                ->whereIn('shift_id', $shiftIds)
                ->where('type_break', 'Sản xuất')
                ->select('shift_id', 'start_time', 'end_time')
                ->orderBy('id')
                ->get();
        });
    }

    function adjustTimeWithinShift($startTime, $duration, $machineId, $shiftPreparationTime)
    {
        // $startTime = Carbon::parse('2024-09-19 01:22:14', 'Asia/Bangkok');
        // $duration = 69;
        // $machineId = 'LH1-A2';
        // $shiftPreparationTime = 15;
        // Đảm bảo $startTime là instance của Carbon và đặt múi giờ
        if (!$startTime instanceof Carbon) {
            $startTime = Carbon::parse($startTime, 'Asia/Bangkok');
        } else {
            $startTime->setTimezone('Asia/Bangkok');
        }

        $currentTime = $startTime->copy();
        $remainingDuration = $duration; // Thời gian còn lại cần phân bổ (đơn vị: phút)

        // Đặt giới hạn số ngày để tránh vòng lặp vô hạn
        $maxDays = 30;

        // Biến đếm số ngày đã xử lý
        $daysProcessed = 0;

        // Xử lý trường hợp startTime nằm trước 7:00, chuyển sang ngày hôm sau
        // if ($currentTime->hour < 7) {
        //     $currentTime->addDay()->startOfDay();
        //     $daysProcessed++;
        // }

        while ($remainingDuration > 0 && $daysProcessed < $maxDays) {
            $currentDate = $currentTime->copy()->startOfDay();
            $dateString = $currentDate->toDateString();

            // Lấy các shift_breaks sản xuất của máy trong ngày hiện tại
            $productionShifts = $this->getMachineProductionShifts($machineId, $dateString);

            if ($productionShifts->isEmpty()) {
                // Nếu không có shift_breaks trong ngày này, chuyển sang ngày tiếp theo
                $currentTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }

            // Duyệt qua từng shift_break để phân bổ thời gian
            foreach ($productionShifts as $shift) {
                $shiftStart = Carbon::parse("{$dateString} {$shift->start_time}", 'Asia/Bangkok');
                $shiftEnd = Carbon::parse("{$dateString} {$shift->end_time}", 'Asia/Bangkok');

                // Xử lý shift_breaks bắt đầu trước 07:00, gán vào ngày hôm sau
                if ($shiftStart->hour < 7 && $startTime->hour >= 7) {
                    $shiftStart->addDay();
                }

                if ($shiftStart->hour >= 7 && $startTime->hour < 7) {
                    $shiftStart->subDay();
                }

                if ($shiftEnd->hour < 7 && $startTime->hour >= 7) {
                    $shiftEnd->addDay();
                }

                // if ($shiftEnd->hour >= 7 && $startTime->hour < 7) {
                //     $shiftEnd->subDay();
                // }

                // // Xử lý ca làm việc qua đêm
                // if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
                //     $shiftEnd->addDay();
                // }

                // Nếu currentTime nằm trước thời gian bắt đầu shift_break, chuyển đến shiftStart và thêm shiftPreparationTime nếu không phải lần đầu
                if ($currentTime->lessThan($shiftStart) && $currentTime->day == $shiftStart->day) {
                    if (!$currentTime->equalTo($startTime)) {
                        $currentTime->addMinutes($shiftPreparationTime);
                    }
                    $currentTime = $shiftStart->copy();
                }

                // Nếu currentTime nằm trong shift_break
                if ($currentTime->between($shiftStart, $shiftEnd) || $currentTime->equalTo($shiftStart)) {
                    $availableTime = $shiftEnd->diffInMinutes($currentTime);

                    if ($availableTime >= $remainingDuration) {
                        // Có đủ thời gian để hoàn thành trong shift_break này
                        $endTime = $currentTime->copy()->addMinutes($remainingDuration);
                        return [$startTime, $endTime];
                    } else {
                        // Phân bổ hết thời gian có trong shift_break này
                        $currentTime = $shiftEnd->copy();
                        $remainingDuration -= $availableTime;

                        // Thêm thời gian chuẩn bị cho shift_break tiếp theo
                        // $currentTime->addMinutes($shiftPreparationTime);
                    }
                }
                // Nếu currentTime lớn hơn shiftEnd, tiếp tục với shift_break tiếp theo
                elseif ($currentTime->greaterThanOrEqualTo($shiftEnd)) {
                    continue;
                }
            }

            // Chuyển sang ngày tiếp theo sau khi duyệt hết các shift_breaks trong ngày
            $currentTime->addDay()->startOfDay();
            $daysProcessed++;
        }
        // dd($startTime, $currentTime);
        // Nếu vẫn còn thời lượng chưa phân bổ sau khi đạt giới hạn ngày
        return [$startTime, $currentTime];
    }






    function getShiftPreparationTime($productId, $lineId)
    {
        // Truy vấn để lấy giá trị thời gian chuẩn bị đầu ca từ bảng spec theo slug 'chuan-bidau-ca'
        $preparationTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'chuan-bidau-ca')
            ->first();

        return $preparationTimeSpec ? $preparationTimeSpec->value : 0; // Nếu không tìm thấy, trả về 0
    }

    function getNumberMachine($orderId)
    {
        // Truy vấn số lượng máy mỗi công đoạn
        $numberMachineOrders = NumberMachineOrder::where('product_order_id', $orderId)->get();
        return $numberMachineOrders->pluck('number_machine', 'line_id');
    }

    public function generateProductionPlan(Request $request)
    {
        $orderIds = $request->order_id;
        $data = [];
        $machine_available_list = [];
        foreach ($orderIds as $index => $orderId) {
            $result = $this->processProductionPlan($orderId, $index, $machine_available_list);
            if ($result) {
                $data[] = $result;
            }
        }
        if (count($data) <= 0) {
            return $this->failure('', 'Không có kế hoạch nào được tạo');
        }
        return $this->success($data);
    }

    public function processProductionPlan($orderId, $orderIndex = 0, &$machine_available_list = [])
    {
        // Lấy thông tin đơn hàng
        $order = ProductOrder::with('product', 'customer')->find($orderId);
        if (!$order->sl_giao_sx) {
            return null;
        }
        $productId = $order->product_id;
        $initialQuantity = $order->sl_giao_sx;

        // Lấy danh sách công đoạn theo thứ tự DESC để tính toán sản lượng
        $productionSteps = $this->getProductionSteps($productId);

        // Lấy danh sách ca làm việc
        // $productionShifts = $this->getProductionShifts();

        // Khai báo mảng để lưu trữ sản lượng của từng công đoạn
        $stepQuantities = [];
        $numberMachineByStep = $this->getNumberMachine($orderId);
        // Tính toán sản lượng cho từng công đoạn theo thứ tự DESC
        foreach ($productionSteps as $step) {
            $calculatedQuantity = $this->calculateProductionOutput($productId, $step->line_id, $initialQuantity);
            $stepQuantities[$step->line_id] = $calculatedQuantity;
            // Cập nhật lại initialQuantity cho công đoạn tiếp theo
            $initialQuantity = $calculatedQuantity;
        }
        // Lấy lại danh sách công đoạn theo thứ tự ASC để tính toán thời gian bắt đầu và kết thúc
        $orderedSteps = $this->getOrderedProductionSteps($productId);

        // Khai báo mảng để lưu trữ thời gian bắt đầu và kết thúc của từng công đoạn
        $stepEndTimes = [];
        $lots = [];
        $plans = [];
        $lot_plans = [];
        $machine_input = [];
        $isExceedDeliveryTime = false;

        //Tạo mã lô sx
        $losx_id = Losx::generateUniqueIdPreview($orderIndex);
        $lo_sx = Losx::where('product_order_id', $order->id)->first();
        if ($lo_sx) {
            $losx_id = $lo_sx->id;
        }
        $losx_input = ['product_order_id' => $order->id, 'id' => $losx_id];
        // Tính toán thời gian bắt đầu và kết thúc cho từng công đoạn theo thứ tự ASC
        foreach ($orderedSteps as $index => $step) {
            $lineId = $step->line_id;
            $quantity = $stepQuantities[$lineId];
            if (!isset($lots[$lineId])) {
                $lots[$lineId] = [];
            }

            // Lấy dữ liệu lotsize tại công đoạn với slug 'so-luong'
            $lotSize = $this->getLotSize($productId, $lineId);

            // Lấy thời gian lên xuống cuộn tại công đoạn với slug 'thoi-gian-len-xuong-cuon'
            $rollChangeTime = $this->getRollChangeTime($productId, $lineId);

            // Lấy năng suất tại công đoạn và tính toán taskTime
            $efficiency = $this->getEfficiency($productId, $lineId);
            $taskTime = $efficiency > 0 ? 60 / $efficiency : 0; // Tính taskTime, nếu năng suất > 0

            // Lấy số lượng cuộn một lần vận chuyển và tính toán thời gian sản xuất cho 1 xe hàng
            $rollsPerTransport = $this->getRollsPerTransport($productId, $lineId);
            // Lấy thời gian vào hàng tại công đoạn với slug 'vao-hang-setup-may'
            $setupTime = $this->getSetupTime($productId, $lineId);

            $shiftPreparationTime = $this->getShiftPreparationTime($productId, $lineId);

            $transportTime = $this->getTransportTimeBetweenSteps($productId, $lineId);

            //Tính thời gian bắt đầu của lô
            if ($index == 0) {
                // Công đoạn đầu tiên
                $startTime = Carbon::now('Asia/Bangkok');
                // $startTime = Carbon::parse('2024-08-29 07:30:00', 'Asia/Bangkok');
            } else {
                // Các công đoạn tiếp theo
                //Nếu số lượng cuộn vận chuyển lớn hơn số lượng lot của công đoạn trước đó thì số lượng cuộn vc = số lượng lot của công đoạn trước đó 
                if ($rollsPerTransport === 0 || (isset($lots[$orderedSteps[$index - 1]->line_id][$numberMachineByStep[$orderedSteps[$index - 1]->line_id] - 1]) && count($lots[$orderedSteps[$index - 1]->line_id][$numberMachineByStep[$orderedSteps[$index - 1]->line_id] - 1]) < $rollsPerTransport)) {
                    $rollsPerTransport = count($lots[$orderedSteps[$index - 1]->line_id][$numberMachineByStep[$orderedSteps[$index - 1]->line_id] - 1]);
                }
                $startTime = $lots[$orderedSteps[$index - 1]->line_id][$numberMachineByStep[$orderedSteps[$index - 1]->line_id] - 1][$rollsPerTransport - 1]['endTime']->copy()->addMinutes($transportTime);
                // return ['startTime'=>$startTime, 'lot'=>$lots, 'machineTime'=>$machineReadyTime];
            }

            // Tính toán thời gian bắt đầu và kết thúc cho từng công đoạn
            $numMachines  = $numberMachineByStep[$lineId] ?? 0;
            $machines = $this->getMachineReady($lineId, $numMachines, $order->product_id, $machine_available_list, $startTime);

            //Tính lại số lượng máy nếu số máy thực tế nhỏ hơn số máy yêu cầu
            $numMachines = count($machines);
            // Tính toán số lượng sản xuất cho mỗi máy
            $quantityPerMachine = $numMachines > 0 ? ceil($quantity / $numMachines) : $quantity;
            $lotIndexOffset = 0; // Offset để đánh số lot cho mỗi máy

            $numLots = ceil($quantityPerMachine / $lotSize); // Tổng số lot, dùng ceil để làm tròn lên
            // Chia lot và tính toán thời gian cho từng lot cho máy nàys
            foreach ($machines as $machineIndex => $machine) {
                $machineReadyTime = Carbon::parse($machine->available_at, 'Asia/Bangkok');
                if (!$startTime->greaterThan($machineReadyTime)) {
                    $startTime = $machineReadyTime;
                }

                // Thời gian kết thúc là thời gian bắt đầu cộng thêm thời gian sản xuất
                $endTime = $startTime->copy()->addMinutes(((($taskTime * $lotSize) + $rollChangeTime) * $numLots) + $setupTime);

                // Lưu trữ thời gian bắt đầu và kết thúc vào mảng
                $stepEndTimes[$lineId] = $endTime;

                //Mã mã lô, nếu đon hàng đã tồn tại lo_sx thì lấy lô cũ, nếu không tạo lô mới

                $plan_input = [
                    'product_order_id' => $order->id,
                    'ngay_dat_hang' => $order->order_date,
                    'ngay_sx' => $startTime,
                    'ngay_giao_hang' => $order->delivery_date,
                    'line_id' => $lineId,
                    'cong_doan_sx' => $step->line->name,
                    'ca_sx' => 1,
                    'delivery_date' => $order->delivery_date ? date('Y-m-d', strtotime($order->delivery_date)) : null,
                    'machine_id' => $machine->code,
                    'product_id' => $order->product_id,
                    'ten_san_pham' => $order->product->name ?? "",
                    'khach_hang' => $order->customer->name ?? "",
                    'lo_sx' => $losx_id,
                    'thu_tu_uu_tien' => 1,
                    'nhan_luc' => 1,
                    'tong_tg_thuc_hien' => ($quantity * $taskTime) + ($rollChangeTime * $numLots) + $setupTime,
                    'thoi_gian_bat_dau' => $startTime,
                    'thoi_gian_ket_thuc' => $endTime,
                    'sl_giao_sx' => $quantityPerMachine,
                ];
                $lot_in_plan = [];
                $countLot = InfoCongDoan::query()->where([
                    'lo_sx' => $losx_id,
                    'line_id' => $lineId
                ])->count();
                for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
                    $countLot += 1;
                    $lotId = $losx_id . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT); // Tạo lot_id với stt lot
                    $lotStartTime = ($lotIndex == 1) ? $startTime : $lots[$lineId][$machineIndex][$lotIndex - 2]['endTime'];
                    list($lotStartTime, $lotEndTime) = $this->adjustTimeWithinShift($lotStartTime, ($taskTime * $lotSize) + $rollChangeTime, $machine->code, $shiftPreparationTime);
                    //Trường hợp thời gian sx vượt quá thời gian giao hàng, đánh dấu KH được tạo
                    if ($order->delivery_date && $lotStartTime->greaterThan(Carbon::parse($order->delivery_date))) {
                        $isExceedDeliveryTime = true;
                    }
                    // Lưu thông tin lot vào object
                    $lot_plan_input = [
                        'lot_id' => $lotId,
                        'lo_sx' => $losx_id,
                        'line_id' => $lineId,
                        'product_id' => $productId,
                        'machine_code' => $machine->code,
                        'start_time' => $lotStartTime,
                        'end_time' => $lotEndTime,
                        'quantity' => $lotSize,
                        'lot_size' => $lotSize,
                        'product_order_id' => $order->id,
                        'customer_id' => 'SamSung',
                        //Thông tin bổ sung không lưu vào db
                        'sl_giao_sx' => $lotSize,
                        'ca_sx' => 1,
                        'cong_doan_sx' => $step->line->name,
                        'machine_id' => $machine->code,
                        'ten_san_pham' => $order->product->name ?? "",
                        'khach_hang' => 'SamSung',
                        'thoi_gian_bat_dau' => $lotStartTime,
                        'thoi_gian_ket_thuc' => $lotEndTime,
                        'is_exceed_time' => $isExceedDeliveryTime,
                    ];
                    $lot_plans[] = $lot_plan_input;
                    $lot_in_plan[] = $lot_plan_input;

                    $lots[$lineId][$machineIndex][] = [
                        'lot_id' => $lotId,
                        'quantity' => $lotSize,
                        'startTime' => $lotStartTime,
                        'endTime' => $lotEndTime,
                    ];
                }
                // $plan_input['children'] = $lot_in_plan;
                $plan_input['is_exceed_time'] = $isExceedDeliveryTime;
                // Thời gian kết thúc của công đoạn là thời gian kết thúc của lot cuối cùng
                if (!empty($lots[$lineId][$machineIndex])) {
                    $stepEndTimes[$lineId] = end($lots[$lineId][$machineIndex])['endTime'];
                    $plan_input['thoi_gian_ket_thuc'] = $stepEndTimes[$lineId];
                    // $plan->update([
                    //     'thoi_gian_ket_thuc' => $stepEndTimes[$lineId],
                    // ]);
                }
                //Tạo kế hoạch
                $plans[] = $plan_input;
                // $plan = ProductionPlan::create($plan);

                $lotIndexOffset += $numLots;
                // $machine->update([
                //     'available_at' => $stepEndTimes[$lineId],
                // ]);
                $machine_input[] = ['machine_code' => $machine->code, 'available_at' => $stepEndTimes[$lineId]];
                if (!isset($machine_available_list[$machine->code]) || $stepEndTimes[$lineId]->greaterThan($machine_available_list[$machine->code])) {
                    $machine_available_list[$machine->code] = $stepEndTimes[$lineId];
                }
            }
        }
        //Gán giá trị cho đơn hàng
        $losx_input['lo_sx'] = $losx_input['id'];
        $losx_input['sl_giao_sx'] = $order->sl_giao_sx;
        $losx_input['product_id'] = $order->product_id;
        $losx_input['product_name'] = $order->product->name ?? "";
        $losx_input['thoi_gian_bat_dau'] = !empty($plans) ? (reset($plans)['thoi_gian_bat_dau'] ?? null) : null;
        $losx_input['thoi_gian_ket_thuc'] = !empty($plans) ? (end($plans)['thoi_gian_ket_thuc'] ?? null) : null;
        $losx_input['khach_hang'] = $order->customer->name ?? "";
        $losx_input['delivery_date'] = $order->delivery_date ? date('d/m/Y', strtotime($order->delivery_date)) : null;
        $losx_input['plans'] = $plans;
        $losx_input['is_exceed_time'] = $isExceedDeliveryTime;
        // Trả về danh sách các công đoạn và các thông số tính toán
        return [
            'lots' => $lot_plans, // Danh sách lot tại mỗi công đoạn
            'plans' => $plans,
            'machines' => $machine_input,
            'lo_sx' => $losx_input,
        ];
        // return $plans;
    }

    public function createProductionPlan(Request $request)
    {
        $plans = $request->plans ?? [];
        if (count($plans) <= 0) {
            return $this->failure('', 'Không có dữ liệu kế hoạch lô');
        }
        $lots = $request->lots ?? [];
        if (count($lots) <= 0) {
            return $this->failure('', 'Không có dữ liệu kế hoạch lot');
        }
        $machines = $request->machines ?? [];
        $lo_sx = $request->lo_sx ?? [];
        try {
            DB::beginTransaction();
            foreach ($plans as $plan) {
                $production_plan = ProductionPlan::create($plan);
                foreach ($plan['children'] ?? [] as $lot_plan) {
                    $lot_plan['production_plan_id'] = $production_plan->id;
                    LotPlan::create($lot_plan);
                }
            }
            foreach ($machines as $machine) {
                Machine::where('code', $machine['machine_code'])->update(['available_at' => $machine['available_at']]);
            }
            foreach ($lo_sx as $value) {
                Losx::updateOrCreate(['id' => $value['id']], ['product_order_id' => $value['product_order_id']]);
                $product_order = ProductOrder::find($value['product_order_id']);
                if ($product_order) {
                    $product_order->update(['sl_da_giao' => $product_order->sl_giao_sx, 'sl_giao_sx' => 0]);
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th->getMessage(), 'Lỗi tạo kế hoạch');
        }
        return $this->success('', 'Đã tạo thành công');
    }
}
