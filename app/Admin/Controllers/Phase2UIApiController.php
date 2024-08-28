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
use App\Models\Lot;
use App\Models\LSXLog;
use App\Models\Machine;
use App\Models\MachineLog;
use App\Models\Material;
use App\Models\ProductionPlan;
use App\Models\ProductOrder;
use App\Models\QCHistory;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\Spec;
use App\Models\TestCriteria;
use App\Models\Tracking;
use App\Models\User;
use App\Models\Workers;
use App\Traits\API;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class Phase2UIApiController extends Controller
{
    use API;
    public function getTreeSelect(Request $request)
    {
        $factories = Factory::with('line.machine')
            // ->select('factories.*', 'id as key', 'name as title', DB::raw("'factory' as type"))
            ->where('id', 2)
            ->get();
        foreach ($factories as $factory) {
            foreach ($factory->line as $line) {
                foreach ($line->machine as $machine) {
                    $machine->key = $machine->id;
                    $machine->title = $machine->name;
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

    public function pqcHistoryQuery(Request $request)
    {
        $line_ids = Line::where('factory_id', 2)->pluck('id')->toArray();
        $query = InfoCongDoan::has('log')->where('line_id', $line_ids)->orderBy('created_at');
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
        $query->whereNotIn('line_id', [9, 21])->whereHas('lot', function ($lot_query) {
            $lot_query->where(function ($q) {
                $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
            })->orWhere(function ($q) {
                $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
            });
        })->with("lot.product", "log", "plan", "line");
        return $query;
    }

    //Danh sách lot PQC
    public function getQualittyDataTable(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->pqcHistoryQuery($request);
        $dateRange = CarbonPeriod::create(date('Y-m-d', strtotime($request->date[0])), date('Y-m-d', strtotime($request->date[1])));
        $dates = array_flip(array_map(fn($date) => $date->format('Y-m-d'), iterator_to_array($dateRange)));
        $data = $query->get()->filter(function ($value, $key) use ($dates) {
            $line_key = $this->ID2TEXT[$value->line_id];
            if (isset($value->log->info['qc'][$line_key]['thoi_gian_vao'])) {
                $thoi_gian_vao = date('Y-m-d', strtotime($value->log->info['qc'][$line_key]['thoi_gian_vao']));
                if (isset($dates[$thoi_gian_vao])) {
                    return $value;
                }
            }
            return false;
        });
        $count = $data->count();
        $records = $data->slice($page * $pageSize, $pageSize);
        $totalPage = $count;
        $table = $this->produceTablePQC($records);
        // $chart = $this->qcError($records);
        return $this->success([
            "table" => $table,
            // "chart_lot" => $chart[1],
            // "chart" => $chart[0],
            "totalPage" => $totalPage,
        ]);
    }

    function getProductionSteps($productId)
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
            ->get();
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
            ->where('slug', 'so-luong-cuon-1-lan-van-chuyen')
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

    function getMachineReady($lineId, $numMachines)
    {
        // Truy vấn bảng machine để lấy máy có available_at nhỏ nhất theo line_id
        $machines = Machine::where('line_id', $lineId)
            ->orderBy('available_at', 'asc')->limit($numMachines)
            ->get();
        return $machines;
    }

    function getProductionShifts()
    {
        // Truy vấn để lấy danh sách ca làm việc từ bảng shift_breaks với type_break = 'Sản xuất'
        return ShiftBreak::where('type_break', 'Sản xuất')
            ->orderBy('start_time', 'asc')
            ->get(['start_time', 'end_time']);
    }

    function adjustTimeWithinShift($startTime, $duration, $shifts, $lotId, $shiftPreparationTime)
    {
        // Thiết lập múi giờ cho startTime
        $startTime->setTimezone('Asia/Bangkok');
        // Kiểm tra và điều chỉnh thời gian trong ca sản xuất
        $startHour = $startTime->toTimeString();
        $firstShift = $shifts->first();
        foreach ($shifts as $shift) {
            $shiftStart = Carbon::parse($shift->start_time, 'Asia/Bangkok')->toTimeString();
            $shiftEnd = Carbon::parse($shift->end_time, 'Asia/Bangkok')->toTimeString();
            // Nếu thời gian bắt đầu nằm trong khoảng thời gian sản xuất
            if ($startHour >= $shiftStart && $startHour <= $shiftEnd) {
                // Tính toán thời gian kết thúc dự kiến

                $endTime = $startTime->copy()->addMinutes($duration);
                $endHour = $endTime->toTimeString();

                // Nếu thời gian kết thúc vượt quá thời gian kết thúc của ca hiện tại
                if ($endHour > $shiftEnd) {
                    // Tính toán thời gian dư thừa cần chuyển sang ca tiếp theo

                    $remainingDuration = Carbon::parse($endHour)->diffInMinutes(Carbon::parse($shiftEnd));

                    // Tìm ca tiếp theo
                    $nextShiftStart = $shifts->where('start_time', '>', $shiftEnd)->first();
                    if ($nextShiftStart) {
                        if ($nextShiftStart->id == $firstShift->id) {
                            $endTime =  Carbon::parse($startTime->toDateString() . ' ' . $nextShiftStart->start_time, 'Asia/Bangkok')->copy()->addMinutes($remainingDuration + $shiftPreparationTime); // Thời gian kết thúc mới trong ca tiếp theo
                        } else {
                            $endTime =  Carbon::parse($startTime->toDateString() . ' ' . $nextShiftStart->start_time, 'Asia/Bangkok')->copy()->addMinutes($remainingDuration); // Thời gian kết thúc mới trong ca tiếp theo
                        }
                    } else {
                        // Nếu không còn ca tiếp theo, quay lại ca đầu tiên của ngày hôm sau
                        $startShift = Carbon::parse($startTime->toDateString() . ' ' . $firstShift->start_time, 'Asia/Bangkok')->addDay();
                        $endTime = $startShift->copy()->addMinutes($remainingDuration + $shiftPreparationTime);
                    }
                    return [$startTime, $endTime];
                } else {
                    // Thời gian kết thúc nằm trong khoảng thời gian sản xuất, trả về
                    return [$startTime, $endTime];
                }
            } elseif ($startHour < $shiftStart) {
                // Nếu thời gian bắt đầu nhỏ hơn thời gian bắt đầu của ca sản xuất hiện tại
                $startTime->setTimeFrom(Carbon::parse($shiftStart, 'Asia/Bangkok'));
                $endTime = $startTime->copy()->addMinutes($duration);

                // Kiểm tra nếu thời gian kết thúc vượt quá thời gian kết thúc của ca
                $endHour = $endTime->toTimeString();
                if ($endHour > $shiftEnd) {
                    // Tính toán thời gian dư thừa cần chuyển sang ca tiếp theo
                    $remainingDuration = Carbon::parse($endHour)->diffInMinutes(Carbon::parse($shiftEnd));

                    // Tìm ca tiếp theo
                    $nextShiftStart = $shifts->where('start_time', '>', $shiftEnd)->first();
                    if ($nextShiftStart) {
                        $startShift = Carbon::parse($startTime->toDateString() . ' ' . $nextShiftStart->start_time, 'Asia/Bangkok')->addDay($startTime->dayOfYear != Carbon::now()->dayOfYear ? 0 : 1);
                        $endTime = $startShift->copy()->addMinutes($remainingDuration); // Thời gian kết thúc mới trong ca tiếp theo
                    } else {
                        // Nếu không còn ca tiếp theo, quay lại ca đầu tiên của ngày hôm sau
                        $startShift = Carbon::parse($startTime->toDateString() . ' ' . $firstShift->start_time, 'Asia/Bangkok')->addDay();
                        $endTime = $startShift->copy()->addMinutes($remainingDuration);
                    }
                }

                return [$startTime, $endTime];
            }
        }
        // Nếu không nằm trong bất kỳ ca sản xuất nào, di chuyển thời gian bắt đầu đến đầu ca sản xuất tiếp theo
        $nextShiftStart = $shifts->where('start_time', '>', $startHour)->first();
        if ($nextShiftStart) {
            $startShift = Carbon::parse($startTime->toDateString() . ' ' . $nextShiftStart->start_time, 'Asia/Bangkok')->addDay($startTime->dayOfYear != Carbon::now()->dayOfYear ? 0 : 1);
            $endTime = $startShift->copy()->addMinutes($duration);
        } else {
            // Nếu không có ca sản xuất tiếp theo trong ngày, quay lại ca đầu tiên của ngày hôm sau
            $firstShift = $shifts->first();
            $startShift = Carbon::parse($startTime->toDateString() . ' ' . $firstShift->start_time, 'Asia/Bangkok')->addDay();
            $endTime = $startShift->copy()->addMinutes($duration + $shiftPreparationTime);
        }
        return [$startTime, $endTime];
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

    public function generateProductionPlan(Request $request)
    {
        $orderIds = $request->order_id;
        foreach ($orderIds as $orderId) {
            $this->processProductionPlan($orderId);
        }
    }

    public function processProductionPlan($orderId)
    {
        // Lấy thông tin đơn hàng
        $order = ProductOrder::find($orderId);
        $productId = $order->product_id;
        $initialQuantity = $order->quantity;

        // Lấy danh sách công đoạn theo thứ tự DESC để tính toán sản lượng
        $productionSteps = $this->getProductionSteps($productId);

        // Lấy danh sách ca làm việc
        $productionShifts = $this->getProductionShifts();

        // Khai báo mảng để lưu trữ sản lượng của từng công đoạn
        $stepQuantities = [];

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
        // Tính toán thời gian bắt đầu và kết thúc cho từng công đoạn theo thứ tự ASC
        foreach ($orderedSteps as $index => $step) {
            $lineId = $step->line_id;
            $quantity = $stepQuantities[$lineId];

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
            // Tính toán số lượng lô cần thiết

            // Tính toán thời gian bắt đầu và kết thúc cho từng công đoạn
            $numMachines  = 2;
            $machines = $this->getMachineReady($lineId, $numMachines);

            // Tính toán số lượng sản xuất cho mỗi máy
            $quantityPerMachine = ceil($quantity / $numMachines);
            $lotIndexOffset = 0; // Offset để đánh số lot cho mỗi máy

            $numLots = ceil($quantityPerMachine  / $lotSize); // Tổng số lot, dùng ceil để làm tròn lên

            // Chia lot và tính toán thời gian cho từng lot cho máy nàys
            foreach ($machines as $machineIndex => $machine) {
                $machineReadyTime = Carbon::parse($machine->available_at, 'Asia/Bangkok');
                if ($index == 0) {
                    // Công đoạn đầu tiên
                    $startTime = Carbon::now('Asia/Bangkok');
                } else {
                    // Các công đoạn tiếp theo
                    $startTime = $lots[$orderedSteps[$index - 1]->line_id][$numMachines - 1][$rollsPerTransport]['startTime']->copy()->addMinutes($transportTime);
                }
                if ($startTime->greaterThan($machineReadyTime)) {
                    $startTime = $startTime;
                } else {
                    $startTime = $machineReadyTime;
                }
                // Thời gian kết thúc là thời gian bắt đầu cộng thêm thời gian sản xuất
                $endTime = $startTime->copy()->addMinutes((($taskTime * $lotSize) + $rollChangeTime) * $numLots + $setupTime);

                // Lưu trữ thời gian bắt đầu và kết thúc vào mảng
                $stepEndTimes[$lineId] = $endTime;
                $plan = ProductionPlan::create([
                    'ngay_dat_hang' => $order->order_date,
                    'line_id' => $lineId,
                    'cong_doan_sx' => $lineId,
                    'ca_sx' => 1,
                    'ngay_giao_hang' => $order->delivery_date,
                    'machine_id' => $machine->code,
                    'product_id' => $order->product_id,
                    'khach_hang' => 'SamSung',
                    'lo_sx' => '240801',
                    'thu_tu_uu_tien' => 1,
                    'nhan_luc' => 1,
                    'tong_tg_thuc_hien' => ($quantity * $taskTime) + ($rollChangeTime * $numLots) + $setupTime,
                    'thoi_gian_bat_dau' => $startTime,
                    'thoi_gian_ket_thuc' => $endTime,
                    'sl_giao_sx' => $quantity,
                ]);
                for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
                    $lotId = '2408' . str_pad($lotIndexOffset + $lotIndex, 2, '0', STR_PAD_LEFT); // Tạo lot_id với stt lot
                    $lotStartTime = ($lotIndex == 1) ? $startTime : $lots[$lineId][$machineIndex][$lotIndex - 2]['endTime'];
                    list($lotStartTime, $lotEndTime) = $this->adjustTimeWithinShift($lotStartTime, ($taskTime * $lotSize) + $rollChangeTime, $productionShifts, $lotId, $shiftPreparationTime);
                    // Lưu thông tin lot vào object
                    InfoCongDoan::create([
                        'lot_id' => $lotId,
                        'lo_sx' => '240801',
                        'line_id' => $lineId,
                        'product_id' => $productId,
                        'machine_code' => $machine->code,
                        'thoi_gian_bat_dau' => $lotStartTime,
                        'thoi_gian_ket_thuc' => $lotEndTime,
                        'sl_kh' => $lotSize,
                        'lotSize' => $lotSize,
                    ]);
                    $lots[$lineId][$machineIndex][] = [
                        'lot_id' => $lotId,
                        'quantity' => $lotSize,
                        'startTime' => $lotStartTime,
                        'endTime' => $lotEndTime,
                    ];
                }

                // Thời gian kết thúc của công đoạn là thời gian kết thúc của lot cuối cùng
                $stepEndTimes[$lineId] = end($lots[$lineId][$machineIndex])['endTime'];
                $plan->update([
                    'thoi_gian_ket_thuc' => $stepEndTimes[$lineId],
                ]);

                $lotIndexOffset += $numLots;
                $machine->update([
                    'available_at' => $stepEndTimes[$lineId],
                ]);
            }
        }
        // Trả về danh sách các công đoạn và các thông số tính toán
        return [
            'lots' => $lots, // Danh sách lot tại mỗi công đoạn
        ];
    }
}
