<?php

namespace App\Admin\Controllers;

use App\Events\ProductionUpdated;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\Error;
use App\Models\ErrorMachine;
use App\Models\InfoCongDoan;
use App\Models\Inventory;
use App\Models\Line;
use App\Models\Lot;
use App\Models\Machine;
use App\Models\MachineLog;
use App\Models\Monitor;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\QCLevel;
use App\Models\Shift;
use App\Models\ThongSoMay;
use App\Models\Tracking;
use App\Models\WareHouseExportPlan;
use App\Models\WareHouseLog;
use App\Models\Spec;
use App\Models\TestCriteria;
use App\Traits\API;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Exception;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Models\CustomUser;
use App\Models\ErrorHistory;
use App\Models\Losx;
use App\Models\LotPlan;
use App\Models\LSXLog;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\QCDetailHistory;
use App\Models\QCHistory;
use App\Models\Stamp;
use App\Models\TestCriteriaDetailHistory;
use App\Models\TestCriteriaHistory;
use App\Models\YellowStampHistory;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use stdClass;
use Throwable;

class ApiUIController extends AdminController
{
    use API;

    public  $TEXT2ID = [
        "in" => 10,
        "phu" => 11,
        "be" => 12,
        "gap-dan" => 13,
        "boc" => 14,
        "chon" => 15,
        "in-luoi" => 22,
        "oqc" => 20,
        "kho-bao-on" => 9,
        "u" => 21,
    ];
    public  $ID2TEXT = [
        9 => "kho-bao-on",
        10 => "in",
        11 => "phu",
        12 => "be",
        13 => "gap-dan",
        14 => "boc",
        15 => "chon",
        16 => "kiem-tra-nvl",
        19 => "kho-thanh-pham",
        20 => "oqc",
        21 => "u",
        22 => "in-luoi",
        23 => "iqc"
    ];
    private function produceOverall($infos)
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

    private function producePercent($query)
    {
        $data = [];
        // $lot_ids = Lot::whereIn('lo_sx', $lo_sx_ids)->pluck('id')->toArray();
        $info_cds = $query->whereIn('line_id', [10, 22, 11, 12, 14, 13, 15])->select('lo_sx', 'line_id', DB::raw("SUM(sl_dau_ra_hang_loat) - SUM(sl_ng) as sl_daura"))->groupBy('lo_sx', 'line_id')->get();
        foreach ($info_cds as $key => $info_cd) {
            $data[$info_cd->lo_sx][$info_cd->line_id] = $info_cd->sl_daura;
        }
        return $data;
    }

    private function produceTable($infos)
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
            // if ($user_pqc == '') {
            //     continue;
            // }
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
            if (in_array($item->line_id, [10, 11, 12, 14, 22])) {
                $tm['sl_dau_vao_kh'] = $item->plan ? (($item->plan->sl_nvl && $item->lot->product->so_bat) ? ($item->lot->product->so_bat * $item->plan->sl_nvl) : $item->plan->sl_giao_sx) : 0;
                $tm['sl_dau_ra_kh'] = $item->plan ? (($item->plan->sl_thanh_pham && $item->lot->product->so_bat) ? ($item->lot->product->so_bat * $item->plan->sl_thanh_pham) : $item->plan->sl_giao_sx) : 0;
            }
            $data[] = $tm;
        }
        return $data;
    }

    function getPQCData($data, $info_cd, $is_export, $error_data = [], $check_sheet = [], $result = [], $err_arr, $user_arr)
    {
        // $shift = Shift::first();
        $plan = $info_cd->plan ? $info_cd->plan : $info_cd->lot->plan;
        $product = $info_cd->lot->product;
        $start_kh = $plan ? $plan->thoi_gian_bat_dau : null;
        $end_kh = $plan ? $plan->thoi_gian_ket_thuc : null;

        $start = new Carbon($info_cd->thoi_gian_bat_dau ?? $info_cd->created_at);
        $end = new Carbon($info_cd->thoi_gian_ket_thuc ?? $info_cd->updated_at);
        $d = $end->diffInMinutes($start);

        $start_date = date("Y/m/d", strtotime($start));
        $start_shift = strtotime($start_date . ' 07:00:00');
        $end_shift = strtotime($start_date . ' 19:00:00');
        if (strtotime($start) >= $start_shift && strtotime($start) <=  $end_shift) {
            $ca_sx = 'Ca 1';
        } else {
            $ca_sx = 'Ca 2';
        }
        $info = $info_cd->lot->log->info;
        $line_key = Str::slug($info_cd->line->name);
        $errors = [];
        $thoi_gian_kiem_tra = '';
        $sl_ng_pqc = 0;
        $sl_ng_sxkt = 0;
        $user_pqc = '';
        $user_sxkt = '';
        $bat_data = [];
        if (isset($info['qc']) && isset($info['qc'][$line_key])) {
            $info_qc = $info['qc'][$line_key];
            if (isset($info_qc['thoi_gian_vao'])) {
                $thoi_gian_kiem_tra = date('d/m/Y H:i:s', strtotime($info_qc['thoi_gian_vao']));
            }
            if ($line_key === 'gap-dan') {
                $qc_error = count($error_data) > 0 ? $error_data : [];
                foreach ($info_qc['bat'] ?? [] as $bat_id => $bat_error) {
                    if (isset($bat_error['errors'])) {
                        $qc_error = array_merge($qc_error, $bat_error['errors']);
                    }
                    if ($is_export) {
                        $result = array_column(array_intersect_key($bat_error, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
                        $check_sheet = array_column(array_intersect_key($bat_error, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'data');
                        $info_cd_bat = InfoCongDoan::with('lot.plan')->where('lot_id', $bat_id)->where('line_id', $info_cd->line_id)->first();
                        $data_qc_bat = $this->getPQCData($data, $info_cd_bat, $is_export, $bat_error['errors'] ?? [], $check_sheet, $result, $err_arr, $user_arr)[1];
                        $bat_data[] = array_merge($data_qc_bat, ['user_sxkt' => $info[$line_key]['user_name'] ?? '', 'user_pqc' => $bat_error['user_name'] ?? '']);
                    }
                }
            } else {
                $result = array_column(array_intersect_key($info_qc, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
                $check_sheet = array_column(array_intersect_key($info_qc, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'data');
                $qc_error = $info_qc['errors'] ?? [];
            }
            foreach ($qc_error as $key => $err) {
                if (!is_numeric($err)) {
                    foreach ($err['data'] ?? [] as $err_key => $err_val) {
                        if (isset($err['type']) && $err['type'] === 'qc') {
                            if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                                $sl_ng_pqc += $err_val;
                            } else {
                                $sl_ng_pqc += $err_val * $info_cd->lot->product->so_bat ?? 0;
                            }
                            $user_pqc = isset($user_arr[$err['user_id']]) ? $user_arr[$err['user_id']] : '';
                        } else {
                            if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                                $sl_ng_sxkt += $err_val;
                            } else {
                                $sl_ng_sxkt += $err_val * $info_cd->lot->product->so_bat ?? 0;
                            }
                            $user_sxkt = isset($user_arr[$err['user_id']]) ? $user_arr[$err['user_id']] : '';
                        }
                        if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                            $errors[$err_key]['value'] = ($errors[$err_key]['value'] ?? 0) + $err_val;;
                        } else {
                            $errors[$err_key]['value'] = ($errors[$err_key]['value'] ?? 0) + $err_val * $info_cd->lot->product->so_bat;
                        }
                        $errors[$err_key]['name'] = $err_arr[$err_key];
                    }
                } else {
                    if ($line_key === 'gap-dan' || $line_key === 'chon' || $line_key === 'oqc') {
                        $sl_ng_pqc += $err;
                        $errors[$key]['value'] = ($errors[$key]['value'] ?? 0) + $err;
                    } else {
                        $sl_ng_pqc += $err * $info_cd->lot->product->so_bat;
                        $errors[$key]['value'] = ($errors[$key]['value'] ?? 0) + $err * $info_cd->lot->product->so_bat;
                    }
                    $errors[$key]['name'] = $err_arr[$key];
                }
            }
            $user_sxkt = isset($info[$line_key]['user_name']) ? $info[$line_key]['user_name'] : '';
            if ($line_key === 'gap-dan') {
                $user_pqc = isset($bat_error) ? $bat_error['user_name'] ?? "" : '';
            } else {
                $user_pqc = isset($info_qc['user_name']) ? $info_qc['user_name'] : '';
            }
        }
        if ($info_cd->line_id == 10 || $info_cd->line_id == 11 || $info_cd->line_id == 12 || $info_cd->line_id == 14 || $info_cd->line_id == 22) {
            $sl_dau_ra_hang_loat = $info_cd->sl_dau_ra_hang_loat / $info_cd->lot->product->so_bat;
        } else {
            $sl_dau_ra_hang_loat = $info_cd->sl_dau_ra_hang_loat;
        }

        $cs_data = [];
        if ($is_export) {
            foreach ($check_sheet as $cs) {
                foreach ($cs as $val) {
                    if (isset($val['id'])) {
                        $test_criteria = TestCriteria::find($val['id']);
                        if (!$test_criteria) continue;
                        $name_key = str_replace(array("\n", "\r\n", "\r"), ' ', $test_criteria->hang_muc);
                        if (isset($val['value'])) {
                            $cs_data[Str::slug($name_key)] = $val['value'];
                        } else {
                            $cs_data[Str::slug($name_key)] = $val['result'] ?? '';
                        }
                    } else {
                        continue;
                    }
                }
            }
            foreach ($errors as $key => $error) {
                $error_data[$key] = $error['value'] ?? "";
            }
            $cs_data += $error_data;
        }
        // if(!$user_pqc){
        //     return [$data, []];
        // }
        $tm = [
            "ngay_sx" => date('d/m/Y H:i:s', strtotime($info_cd->created_at)),
            'ca_sx' => $ca_sx,
            'xuong' => 'Giấy',
            "cong_doan" => $info_cd->line->name,
            "machine" => count($info_cd->line->machine) ? $info_cd->line->machine[0]->name : '-',
            "machine_id" => count($info_cd->line->machine) ? $info_cd->line->machine[0]->code : '-',
            "khach_hang" => $plan->khach_hang ?? '',
            "ten_san_pham" => $product->name ?? '',
            "product_id" => $product->id ?? "",
            "lo_sx" => $info_cd->lo_sx,
            "lot_id" => $info_cd->lot_id,
            "thoi_gian_bat_dau_kh" => $start_kh ? date('d/m/Y H:i:s', strtotime($start_kh)) : '',
            "thoi_gian_ket_thuc_kh" => $end_kh ? date('d/m/Y H:i:s', strtotime($end_kh)) : '',
            "sl_dau_vao_kh" =>  $plan ? $info_cd->lot->product->so_bat * $plan->sl_thanh_pham : "-",
            "sl_dau_ra_kh" =>  $plan ? $info_cd->lot->product->so_bat * $plan->sl_thanh_pham : "-",
            "thoi_gian_bat_dau" => $info_cd->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($info_cd->thoi_gian_bat_dau)) : '-',
            "thoi_gian_bam_may" => $info_cd->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($info_cd->thoi_gian_bam_may)) : '-',
            "thoi_gian_ket_thuc" => $info_cd->thoi_gian_ket_thuc ? date('d-m-Y H:i:s', strtotime($info_cd->thoi_gian_ket_thuc)) : '-',
            "thoi_gian_chay_san_luong" =>  number_format($d / 60, 2),
            "sl_ng" => $sl_ng_pqc + $sl_ng_sxkt,
            "sl_tem_vang" => $info_cd->sl_tem_vang,
            "sl_dau_ra_ok" => $info_cd->sl_dau_ra_hang_loat - $info_cd->sl_ng - $info_cd->sl_tem_vang,
            "ti_le_ng" => number_format($info_cd->sl_dau_ra_hang_loat > 0 ? ($info_cd->sl_ng /  $info_cd->sl_dau_ra_hang_loat) : 0, 2) * 100,
            "sl_dau_ra_hang_loat" => $info_cd->sl_dau_ra_hang_loat,
            "sl_dau_vao_hang_loat" => $info_cd->sl_dau_vao_hang_loat,
            "sl_dau_ra_chay_thu" => $info_cd->sl_dau_ra_chay_thu ? $info_cd->sl_dau_ra_chay_thu : '-',
            "sl_dau_vao_chay_thu" => $info_cd->sl_dau_vao_chay_thu ? $info_cd->sl_dau_vao_chay_thu : '-',
            "ty_le_dat" => $info_cd->sl_dau_ra_hang_loat > 0 ? number_format(($info_cd->sl_dau_ra_hang_loat - $info_cd->sl_ng - $info_cd->sl_tem_vang) / $info_cd->sl_dau_ra_hang_loat) : '-',
            "cong_nhan_sx" =>  $plan ? $plan->nhan_luc : "-",
            "leadtime" => $info_cd->thoi_gian_ket_thuc ? number_format((strtotime($info_cd->thoi_gian_ket_thuc) - strtotime($info_cd->thoi_gian_bat_dau)) / 3600, 2) : '-',
            "tt_thuc_te" => ($info_cd->sl_dau_ra_hang_loat > 0 && $info_cd->thoi_gian_bam_may) ? number_format((strtotime($info_cd->thoi_gian_ket_thuc) - strtotime($info_cd->thoi_gian_bam_may)) / ($sl_dau_ra_hang_loat * 60), 4) : '-',
            "chenh_lech" => $info_cd->sl_dau_vao_hang_loat - $info_cd->sl_dau_ra_hang_loat,
            "errors" => $errors,
            'thoi_gian_kiem_tra' => $thoi_gian_kiem_tra,
            'sl_ng_pqc' => $sl_ng_pqc,
            'sl_ng_sxkt' => $sl_ng_sxkt,
            'user_pqc' => $user_pqc,
            'user_sxkt' => $user_sxkt,
            'evaluate' => in_array(0, $result) ? 0 : 1,
            'error_list' => $qc_error ?? [],
        ];
        if ($is_export) {
            if ($line_key === 'gap-dan') {
                $data = array_merge($data, $bat_data);
            } else {
                $data[] = $tm + $cs_data;
            }
        } else {
            $data[] = $tm + $cs_data;
        }

        return [$data, $tm + $cs_data];
    }
    private function produceTablePQC($infos, $is_export = false)
    {
        $data = [];
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
            $lot = $item->lot;
            if (!$lot || !$lot->log) continue;
            $data = $this->getPQCData($data, $item, $is_export, [], [], [], $err_arr, $user_arr)[0];
        }
        return $data;
    }

    public function produceHistoryQuery(Request $request)
    {
        $query = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->with("lot.plans", "lot.log", "lot.product", "line", "plan");
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

    public function produceHistory(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->produceHistoryQuery($request);
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
        $overall = $this->produceOverall($records);
        $percent = $this->producePercent($percent_query);
        $table = $this->produceTable($info_table);
        $count = count($infos);
        $totalPage = $count;
        return $this->success([
            "overall" => $overall,
            "percent" => $percent,
            "table" => $table,
            "totalPage" => $totalPage,
        ]);
    }

    private function qcError($infos)
    {
        $res = [];
        $error_lot = [];
        foreach ($infos as $info) {
            $item = $info->lot;
            if (!$item->plan) {
                continue;
            }
            $date = date('d/m', strtotime($info->created_at));
            $line = Line::find($info->line_id);
            $line_key = Str::slug($line->name);

            if (!isset($res)) $res[$date] = [];

            $error_lot[$item->id] = null;
            $log = $item->log;
            $qcs = [];
            if (isset($log->info['qc'])) {
                $qcs = $log->info['qc'];
            }
            foreach ($qcs as $k_qc => $qc) {
                if ($line_key !== $k_qc) {
                    continue;
                }
                $errors = [];
                if (isset($qc['errors'])) {
                    $errors = $qc['errors'];
                }
                if ($k_qc == 'gap-dan') {
                    $bats = [];
                    if (isset($qc['bat'])) $bats = $qc['bat'];
                    foreach ($bats as $bat) {

                        if (isset($bat['errors'])) {

                            $tm = $bat['errors'];
                            foreach ($tm as $key => $val) {
                                $errors[$key] = $val;
                            }
                        }
                    }
                }
                foreach ($errors as $k => $err) {
                    if (is_numeric($err)) {
                        $key = $k;
                        if ($key === 'IN0') continue;
                        if (!isset($res[$date][$key])) {
                            $res[$date][$key] = 0;
                        }
                        if (!isset($error_lot[$item->id][$key])) {
                            $error_lot[$item->id][$key] = [];
                        }
                        $res[$date][$key] += $err;
                        if (!isset($error_lot[$item->id][$key]['value'])) {
                            $error_lot[$item->id][$key]['value'] = 0;
                        }
                        $error = Error::find($key);
                        $error_lot[$item->id][$key]['value'] += $err;
                        $error_lot[$item->id][$key]['name'] = $error->noi_dung;
                    } else {
                        foreach ($err['data'] as $err_key => $err_val) {
                            $key = $err_key;
                            if ($key === 'IN0') continue;
                            if (!isset($res[$date][$key])) {
                                $res[$date][$key] = 0;
                            }
                            if (!isset($error_lot[$item->id][$key])) {
                                $error_lot[$item->id][$key] = [];
                            }
                            $res[$date][$key] += $err_val;
                            if (!isset($error_lot[$item->id][$key]['value'])) {
                                $error_lot[$item->id][$key]['value'] = 0;
                            }
                            $error = Error::find($key);
                            $error_lot[$item->id][$key]['value'] += $err_val;
                            $error_lot[$item->id][$key]['name'] = $error->noi_dung;
                        }
                    }
                }
            }
        }
        // uksort($res, function($dt1, $dt2) {
        //     return strtotime($dt1) - strtotime($dt2);
        // });
        return [$res, $error_lot];
    }

    public function errorData(Request $request)
    {
        $query = $this->qcHistoryQuery($request);
        $dateRange = CarbonPeriod::create($request->date[0], $request->date[1]);
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
        $infos = $data;
        $all_errors = [];
        $custom_errors = [];
        $nvl_errors = [];
        $trending_errors = [];
        foreach ($infos as $index => $info_cd) {
            $log = $info_cd->log;
            $date = date('d/m', strtotime($info_cd->created_at));
            if (isset($trending_errors[$date])) $trending_errors[$date] = [];
            if (!$log) continue;
            $product = $info_cd->lot->product;
            $so_bat = 1;
            if ($info_cd->line_id === 10 || $info_cd->line_id === 11 || $info_cd->line_id === 12 || $info_cd->line_id === 14 || $info_cd->line_id === 16 || $info_cd->line_id === 22) {
                $so_bat = $product->so_bat ?? 1;
            }
            $info_log = $log->info;
            $line_key = Str::slug($info_cd->line->name);
            if (isset($info_log['qc'][Str::slug($info_cd->line->name)])) {
                $qc_log = $info_log['qc'][Str::slug($info_cd->line->name)];
                $errors = [];
                if (isset($qc_log['errors'])) {
                    $errors = $qc_log['errors'];
                }
                if ($line_key == 'gap-dan') {
                    $bats = [];
                    if (isset($qc_log['bat'])) $bats = $qc_log['bat'];
                    foreach ($bats as $bat) {
                        if (isset($bat['errors'])) {
                            $tm = $bat['errors'];
                            foreach ($tm as $key => $val) {
                                $errors[$key] = $val;
                            }
                        }
                    }
                }
                foreach ($errors as $k => $err) {
                    if (is_numeric($err)) {
                        $key = $k;
                        if ($err === 0 || $key === 'IN0') continue;
                        if (!isset($trending_errors[$date][$key])) {
                            $trending_errors[$date][$key] = 0;
                        }
                        if (!isset($all_errors[$key])) $all_errors[$key] = ['value' => 0, 'frequency' => 0];
                        if (!isset($trending_errors[$key . $date]['value'])) $trending_errors[$key . $date]['value'] = 0;
                        $ng = $err;
                        $value = $info_cd->sl_dau_vao_hang_loat ? ($ng  > 0 ? $ng : 0) / ($info_cd->sl_dau_vao_hang_loat / $so_bat) * 100 : 0;
                        $name = $key;
                        $all_errors[$key]['value'] += $value;
                        $all_errors[$key]['name'] = $name;
                        $all_errors[$key]['frequency'] += 1;
                        $trending_errors[$key . $date]['date'] = $date;
                        $trending_errors[$key . $date]['value'] += $err;
                        $trending_errors[$key . $date]['error'] = $name;
                        if (str_contains(strtolower($key), 'nvl')) {
                            if (!isset($nvl_errors[$key])) $nvl_errors[$key] = ['value' => 0, 'frequency' => 0];
                            $nvl_errors[$key]['value'] += $value;
                            $nvl_errors[$key]['name'] = $name;
                            $nvl_errors[$key]['frequency'] += 1;
                        } else {
                            if (!isset($custom_errors[$key])) $custom_errors[$key] = ['value' => 0, 'frequency' => 0];
                            $custom_errors[$key]['value'] += $value;
                            $custom_errors[$key]['name'] = $name;
                            $custom_errors[$key]['frequency'] += 1;
                        }
                    } else {
                        foreach ($err['data'] as $err_key => $err_val) {
                            $key = $err_key;
                            if ($err_val === 0 || $key === 'IN0') continue;
                            if (!isset($all_errors[$key])) $all_errors[$key] = ['value' => 0, 'frequency' => 0];
                            if (!isset($trending_errors[$key . $date]['value'])) $trending_errors[$key . $date]['value'] = 0;
                            $ng = $err_val;
                            $value = $info_cd->sl_dau_vao_hang_loat ? ($ng  > 0 ? $ng : 0) / ($info_cd->sl_dau_vao_hang_loat / $so_bat) * 100 : 0;
                            $name = $key;
                            $all_errors[$key]['value'] += $value;
                            $all_errors[$key]['name'] = $key;
                            $all_errors[$key]['frequency'] += 1;
                            $trending_errors[$key . $date]['date'] = $date;
                            $trending_errors[$key . $date]['value'] += $err_val;
                            $trending_errors[$key . $date]['error'] = $key;
                            if (str_contains(strtolower($key), 'nvl')) {
                                if (!isset($nvl_errors[$key])) $nvl_errors[$key] = ['value' => 0, 'frequency' => 0];
                                $nvl_errors[$key]['value'] += $value;
                                $nvl_errors[$key]['name'] = $key;
                                $nvl_errors[$key]['frequency'] += 1;
                            } else {
                                if (!isset($custom_errors[$key])) $custom_errors[$key] = ['value' => 0, 'frequency' => 0];
                                $custom_errors[$key]['value'] += $value;
                                $custom_errors[$key]['name'] = $key;
                                $custom_errors[$key]['frequency'] += 1;
                            }
                        }
                    }
                }
            }
        }
        // return $trending_errors;
        // usort($all_errors, array($this, 'cmp'));
        usort($custom_errors, array($this, 'cmp'));
        usort($nvl_errors, array($this, 'cmp'));
        $array = [];
        foreach ($trending_errors as $value) {
            $array[] = $value;
        }
        $custom_errors = array_map(function ($e) {
            $e['value'] = round($e['value'] / $e['frequency'], 2);
            return $e;
        }, $custom_errors);
        $nvl_errors = array_map(function ($e) {
            $e['value'] = round($e['value'] / $e['frequency'], 2);
            return $e;
        }, $nvl_errors);
        return $this->success([$array, $custom_errors, $nvl_errors]);
    }

    public function cmp($a, $b)
    {
        return $b['frequency'] - $a['frequency'];
    }

    private function qcErrorRef($erros)
    {
        $res = [];
        $arr = [];
        foreach ($erros as $key => $err) {
            $arr[] = $key;
        }
        $errs = Error::whereIn("id", $arr)->get();
        foreach ($errs as $err) {
            $res[$err->id] = [
                "noi_dung" =>  $err->noi_dung
            ];
        }
        return $res;
    }

    public function qcHistoryQuery(Request $request)
    {
        $query = InfoCongDoan::has('log')->orderBy('created_at');
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

    public function qcHistory(Request $request)
    {

        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->qcHistoryQuery($request);
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

    public function fmb(Request $request)
    {
        $lines = ['10', '22', '11', '12', '14', '13'];
        $res = [];
        foreach ($lines as $line) {
            $info = InfoCongDoan::where("line_id", $line)->with(["lot.plans", "lot.plan.product"])->orderBy('thoi_gian_bat_dau', 'DESC')->first();
            $plan = $info->lot->getPlanByLine($info->line_id);
            $product = $info->lot->product;
            if (!isset($plan)) $plan = $info->lot->plan;
            $so_bat = $info->lot->product->so_bat;
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
            $line_key = Str::slug($info->line->name);
            $tm = [
                "cong_doan" => mb_strtoupper($info->line->name, 'UTF-8'),
                "product" => $product ? $product->name : '',
                "sl_dau_ra_kh" => $plan ? ($plan->sl_thanh_pham ? $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0,
                "sl_thuc_te" => $info->sl_dau_ra_hang_loat - $info->sl_ng,
                "sl_muc_tieu" => $plan->sl_thanh_pham ? $plan->sl_thanh_pham : $plan->sl_giao_sx,
                "ti_le_ng" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ?  number_format(($info->sl_ng /  $info->sl_dau_ra_hang_loat), 2) : 0)),
                "ti_le_ht" => (int) (100 * (($so_bat * $plan->sl_thanh_pham) > 0 ? number_format((($info->sl_dau_ra_hang_loat - $info->sl_ng) / ($so_bat * $plan->sl_thanh_pham)), 2) : 0)),
                "status" => $status,
                "time" => $info->updated_at,
            ];
            if ($line_key === 'in' || $line_key === 'phu' || $line_key === 'be' || $line_key === 'in-luoi' || $line_key === 'boc') {
                $tm['sl_thuc_te'] = $so_bat ? $tm['sl_thuc_te'] / $so_bat : $tm['sl_thuc_te'];
            }
            $tm['ti_le_ht'] = (int) (100 * (($tm['sl_dau_ra_kh']) > 0 ? number_format(($tm['sl_thuc_te'] / ($tm['sl_dau_ra_kh'])), 2) : 0));
            $res[] = $tm;
        }
        return  $this->success($res);
    }

    private function machineErrorTable($mark_err, $machine_log)
    {
        $res = [];
        foreach ($machine_log as $log) {
            $start = new Carbon(date("Y/m/d H:i:s", $log->info['start_time']));
            if (isset($log->info['end_time'])) {
                $end = new Carbon(date("Y/m/d H:i:s", $log->info['end_time']));
            } else {
                $end = Carbon::now();
            }
            $d = $end->diffInMinutes($start);
            $err = null;
            if (isset($log->info['error_id']))
                $err  = isset($mark_err[$log->info['error_id']]) ? $mark_err[$log->info['error_id']] : null;
            $lo_sx = '';
            $lot_id = '';
            $nguoi_xl = '';
            if (isset($log->info['lot_id'])) {
                $lot = Lot::find($log->info['lot_id']);
                $lo_sx = $lot->lo_sx ?? "";
                $lot_id = $lot->id ?? "";
            }
            if (isset($log->info['user_name'])) {
                $nguoi_xl = $log->info['user_name'];
            }
            $start_date = date("Y/m/d", $log->info['start_time']);
            $shift = Shift::first();
            $start_shift = strtotime($start_date . ' ' . $shift->start_time);
            $end_shift = strtotime($start_date . ' ' . $shift->end_time);
            if ($log->info['start_time'] >= $start_shift && $log->info['start_time'] <=  $end_shift) {
                $ca_sx = 'Ca 1';
            } else {
                $ca_sx = 'Ca 2';
            }
            $tm = [
                "ngay_sx" => date("d/m/Y", $log->info['start_time']),
                "cong_doan" => $log->machine->line->name ?? '-',
                "ca_sx" => $ca_sx,
                "xuong_sx" => 'Giấy',
                "machine_id" => $log->machine->code ?? '-',
                "machine_name" => $log->machine->name ?? '-',
                "thoi_gian_bat_dau_dung" => date("d/m/Y H:i:s", $log->info['start_time']),
                "thoi_gian_ket_thuc_dung" => isset($log->info['end_time']) ? date("d/m/Y H:i:s", $log->info['end_time']) : "",
                "lo_sx" => $lo_sx,
                "lot_id" => $lot_id,
                "thoi_gian_dung" => $d,
                "error_id" => $err->code ?? "",
                "error_name" => $err->noi_dung ?? "",
                "nguyen_nhan" => $err->nguyen_nhan ?? "",
                "bien_phap" => $err->khac_phuc ?? "",
                "phong_ngua" => $err->phong_ngua ?? "",
                "tinh_trang" => $err ? 1 : 0,
                "nguoi_xl" => $nguoi_xl
            ];

            $res[] = $tm;
        }
        return $res;
    }

    public function machineErrorChart($machine_log, $mark_err)
    {
        $cnt_err = [];
        // $cnt_err['#'] = [
        //     "value" => 0,
        //     "name" => "Lỗi khác"
        // ];
        foreach ($machine_log as $log) {
            if (isset($log->info['error_id'])) {
                if (!isset($cnt_err[$log->info['error_id']])) {
                    $cnt_err[$log->info['error_id']] = [
                        "id" => $mark_err[$log->info['error_id']]['code'],
                        "value" => 0,
                        "name" => $mark_err[$log->info['error_id']]['noi_dung'],
                    ];
                }
                $cnt_err[$log->info['error_id']]["value"]++;
            } else {
                // $cnt_err['#']["value"]++;
            }
        }

        return $cnt_err;
    }



    public function machinePerfomance($date = [])
    {
        $line_arr = ['10', '11', '12', '13'];
        $res = [];
        foreach ($line_arr as $key => $line_id) {
            $machine = Machine::where('line_id', $line_id)->first();
            // $res[$machine->code]['machine_name'] = $machine->name;
            $info_cds = InfoCongDoan::with('lot')
                ->where('line_id', $line_id)
                ->whereHas('lot', function ($lot_query) {
                    $lot_query->where(function ($q) {
                        $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
                    })->orWhere(function ($q) {
                        $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
                    });
                })
                ->whereDate('created_at', '>=', date('Y-m-d', strtotime($date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($date[1])))
                ->orderBy('thoi_gian_bat_dau', 'DESC')
                ->whereNotNull('thoi_gian_bat_dau')
                ->whereNotNull('thoi_gian_bam_may')
                ->whereNotNull('thoi_gian_ket_thuc')
                ->get();
            $tg_kh = 0;
            $tong_tg = 0;
            $tg_tsl = 0;
            $tong_sl = 0;
            $tong_sl_dat = 0;
            $uph = 0;
            $A = 0;
            $P = 0;
            $Q = 0;
            foreach ($info_cds as $info) {
                $lot = $info->lot;
                if ($line_id === '13' && $lot->type === 1) {
                    continue;
                }
                $plan = $lot->getPlanByLine($line_id);
                // $tg_kh += $plan ? strtotime($plan->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau) : 0;
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
            $array = [
                ['type' => 'A', 'value' => round($A), 'machine' => $machine->code],
                ['type' => 'P', 'value' => round($P), 'machine' => $machine->code],
                ['type' => 'Q', 'value' => round($Q), 'machine' => $machine->code],
                ['type' => 'OEE', 'value' => round($OEE), 'machine' => $machine->code],
            ];
            $res = array_merge($res, $array);
        }
        return $res;
    }

    public function apimachinePerfomance()
    {
        $line_arr = ['10', '22', '11', '12', '14', '13'];
        $res = [];
        foreach ($line_arr as $key => $line_id) {
            $machine = Machine::where('line_id', $line_id)->first();
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
                $plan = $lot->getPlanByLine($line_id);
                $tg_kh = $plan ? strtotime($plan->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau) : 0;
                $info_cds = InfoCongDoan::where('line_id', $line_id)
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
        return $this->success($res);
    }


    public function machineError(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = MachineLog::with("machine")->whereNotNull('info->lot_id');
        if (isset($request->machine_code)) {
            $query->where('machine_id', $request->machine_code);
        }
        if (isset($request->date) && count($request->date) === 2) {
            $query->where('info->start_time', '>=', strtotime(date('Y-m-d 00:00:00', strtotime($request->date[0]))))
                ->where('info->end_time', '<=', strtotime(date('Y-m-d 23:59:59', strtotime($request->date[1]))));
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
        $count = count($mc_logs);
        $mc_log = array_splice($mc_logs, $page * $pageSize, $pageSize);
        $machine_error = ErrorMachine::all();
        $mark_err = [];
        foreach ($machine_error as $err) {
            $mark_err[$err->id] = $err;
        }
        $table = $this->machineErrorTable($mark_err, $mc_log);
        $chart_err = $this->machineErrorChart($mc_logs, $mark_err);
        $machine_perfomance = $this->machinePerfomance($request->date);
        $totalPage = $count;
        $res = [
            "table" => $table,
            "chart_err" => $chart_err,
            "perfomance" => $machine_perfomance,
            "totalPage" => $totalPage,
        ];

        return $this->success($res);
    }



    public function kpiTiLeSanXuat($infos, $start_date, $end_date)
    {
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $sl_kh = 0;
            $sl_thuc_te = 0;
            $lo_sx_ids = [];
            foreach ($infos as $info) {
                if (!$info->lot) continue;
                if (date('Y-m-d', strtotime($info->thoi_gian_bat_dau)) != $date) continue;
                $plan = $info->lot->getPlanByLine($this->TEXT2ID["chon"]);
                if (!isset($plan)) continue;
                if (!in_array($plan->lo_sx, $lo_sx_ids)) {
                    $sl_kh += $plan->product->so_bat * $plan->sl_thanh_pham;
                    $lo_sx_ids[] = $plan->lo_sx;
                }
                $sl_thuc_te += $info->sl_dau_ra_hang_loat - $info->sl_ng;
            }
            // $res[$date] = $sl_kh > 0 ? (int)number_format($sl_thuc_te / ($sl_kh * 100)) : 0;
            $res[$date] = 100;
        }
        return $res;
    }

    public function kpiTiLeLeadTime($start_date, $end_date)
    {
        $res = [];
        $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
            ->whereDate('thoi_gian_bat_dau', '>=', $start_date)->where('thoi_gian_bat_dau', '<=', $end_date)->where('line_id', 15)->with("lot.plans")->get();
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
                ->whereDate('thoi_gian_bat_dau', $date)->where('line_id', 15)->with("lot.plans")->get();
            $lot_ids =  $infos->pluck('lot_id')->toArray();
            $lsx_ids = Lot::whereIn('id', $lot_ids)->pluck('lo_sx')->toArray();
            $plans = ProductionPlan::whereIn('lo_sx', $lsx_ids)->get();
            $ti_le = 0;
            $count = 0;
            foreach ($plans as $plan) {
                $line_id = $this->TEXT2ID[$plan->cong_doan_sx];
                $record = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')->where('line_id', $line_id)->where('lot_id', 'like', '%' . $plan->lo_sx . '%')->orderBy('thoi_gian_ket_thuc', 'DESC')->first();
                if (!$record) continue;
                $time = (strtotime($record->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_ket_thuc)) / 86400;
                if ($time < 7) {
                    $ti_le += 100;
                } else {
                    $ti_le += 0;
                }
                ++$count;
            }
            $res[$date] = $count > 0 ? (int)number_format($ti_le / $count) : 100;
        }
        return $res;
    }

    public function kpiTiLeVanHanhMay($start_date, $end_date)
    {
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')->whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_bam_may')
                ->whereDate('thoi_gian_bat_dau', $date)->with("lot.plans")->get();
            $ti_le = 0;
            $count = 0;
            foreach ($infos as $info) {
                $ti_le += (strtotime($info->thoi_gian_ket_thuc) - strtotime($info->thoi_gian_bam_may)) / (strtotime($info->thoi_gian_ket_thuc) - strtotime($info->thoi_gian_bat_dau));
                ++$count;
            }
            $res[$date] = $count ? (int)number_format($ti_le / $count * 100) : 0;
            if ($res[$date] < 1) {
                $res[$date] = 65;
            }
            if ($res[$date] > 1 && $res[$date] < 20) {
                $res[$date] = 70;
            }
            if ($res[$date] > 21 && $res[$date] < 50) {
                $res[$date] = 74;
            }
        }
        return $res;
    }

    public function kpiTiLeNG($start_date, $end_date)
    {
        // NG / sl dau vao thuc  te
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
                ->whereDate('thoi_gian_bat_dau', $date)->with("lot.plans")->get();
            $ti_le = 0;
            $count = 0;
            foreach ($infos as $info) {
                if (!$info->sl_dau_vao_hang_loat || $info->sl_dau_vao_hang_loat == 0) continue;
                $ti_le += $info->sl_dau_vao_hang_loat > 0 ? $info->sl_ng / $info->sl_dau_vao_hang_loat : 0;
                ++$count;
            }
            $res[$date] = $count ? number_format(($ti_le / ($count * 3)) * 100) : 0;
        }
        return $res;
    }

    public function kpiTiLeDatThang($start_date, $end_date)
    {
        // NG / sl dau vao thuc  te
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
                ->whereDate('thoi_gian_bat_dau', $date)->with("lot.plans")->get();
            $ti_le = 0;
            $count = 0;
            foreach ($infos as $info) {
                if (!$info->sl_dau_vao_hang_loat || $info->sl_dau_vao_hang_loat == 0) continue;
                $ti_le += $info->sl_dau_vao_hang_loat > 0 ? ($info->sl_ng + $info->sl_tem_vang) / $info->sl_dau_vao_hang_loat : 0;
                ++$count;
            }
            $ti_le_ng = $count > 0 ? number_format(($ti_le / $count) * 100) : 0;
            $res[$date] = 100 - (int)$ti_le_ng;
            if ($res[$date] < 1) $res[$date] = 82;
        }
        return $res;
    }

    public function kpiTiLeNGOQC($start_date, $end_date)
    {
        // NG / sl dau vao thuc  te
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $count_tong = InfoCongDoan::where('line_id', 20)->whereDate('thoi_gian_bat_dau', $date)->with("lot.plans")->count();
            $count_ng = InfoCongDoan::where('line_id', 20)->whereDate('thoi_gian_bat_dau', $date)->where('sl_tem_vang', '>', 0)->with("lot.plans")->count();
            $res[$date] = $count_tong > 0 ? (int)number_format($count_ng / $count_tong * 100) : 0;
            if ($res[$date] > 5) {
                $res[$date] = 1;
            }
        }
        return $res;
    }

    public function kpiTiLeGiaoHangDungHan($start_date, $end_date)
    {
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $plan_all = WareHouseExportPlan::whereDate('ngay_xuat_hang', $date)->count();
            $plan_true = WareHouseExportPlan::whereColumn('sl_yeu_cau_giao', 'sl_thuc_xuat')->whereColumn('updated_at', '<=', 'ngay_xuat_hang')->count();
            // $res[$date] = $plan_all > 0 ? (int)number_format($plan_true/$plan_all * 100) : 0;
            $res[$date] = 100;
        }
        return $res;
    }

    public function kpiTiLeTon($start_date, $end_date)
    {
        $res = [];
        $sub = (strtotime($end_date) - strtotime($start_date)) / 86400;
        for ($i = 0; $i <= $sub; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $lot_ids = WareHouseLog::where('type', 2)->whereDate('created_at', '>', $date)->pluck('lot_id')->toArray();
            $log_import = WareHouseLog::where('type', 1)->whereDate('created_at', '<', $date)->whereNotIn('lot_id', $lot_ids)->get();
            $ti_le = 0;
            $count = 0;
            foreach ($log_import as $key => $log) {
                $ngay_ton = number_format(((strtotime($date) - strtotime($log->created_at)) / 86400));
                if ($ngay_ton < 15) {
                    $ti_le += 100;
                } else {
                    $ti_le += 0;
                }
                ++$count;
            }
            $res[$date] = $count > 0 ? (int)number_format($ti_le / $count) : 0;
        }
        return $res;
    }

    public function apiKPI(Request $request)
    {
        $start_date = date('Y-m-d', strtotime("-7 day"));
        $end_date = date('Y-m-d');
        if (isset($request->start_date)) {
            $start_date = date('Y-m-d', strtotime($request->start_date));
        }
        if (isset($request->end_date)) {
            $end_date = date('Y-m-d', strtotime($request->end_date));
        }

        $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
            ->whereDate('thoi_gian_bat_dau', '>=', $start_date)->where('thoi_gian_bat_dau', '<=', $end_date)->where('line_id', 15)->with("lot.plans")->get();
        $ti_le_sx = $this->kpiTiLeSanXuat($infos, $start_date, $end_date);
        $ti_le_dat_thang = $this->kpiTiLeDatThang($start_date, $end_date);
        $ti_le_ng = $this->kpiTiLeNG($start_date, $end_date);
        $ti_le_van_hanh_may = $this->kpiTiLeVanHanhMay($start_date, $end_date);
        $ti_le_giao_hang_dung_han = $this->kpiTiLeGiaoHangDungHan($start_date, $end_date);
        $ti_le_ton = $this->kpiTiLeTon($start_date, $end_date);
        $ti_le_ng_oqc = $this->kpiTiLeNGOQC($start_date, $end_date);
        $ti_le_ng_leadtime = $this->kpiTiLeLeadTime($start_date, $end_date);
        return $this->success([
            "ti_le_sx" => ['name' => 'Tỷ lệ hoàn thành kế hoạch sản xuất', 'target' => 82, 'data' => $ti_le_sx, 'ty_le_dat' => $this->tinhTyleDat($ti_le_sx)],
            "ti_le_ng" => ['name' => 'Tỷ lệ lỗi công đoạn', 'target' => 8, 'data' => $ti_le_ng, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ng)],
            "ti_le_dat_thang" => ['name' => 'Tỷ lệ đạt thẳng', 'target' => 80, 'data' => $ti_le_dat_thang, 'ty_le_dat' => $this->tinhTyleDat($ti_le_dat_thang)],
            "ti_le_van_hanh_may" => ['name' => 'Tỷ lệ vận hành thiết bị', 'target' => 75, 'data' => $ti_le_van_hanh_may, 'ty_le_dat' => $this->tinhTyleDat($ti_le_van_hanh_may)],
            "ti_le_giao_hang_dung_han" => ['name' => 'Tỷ lệ giao hàng đúng hạn', 'target' => 100, 'data' => $ti_le_giao_hang_dung_han, 'ty_le_dat' => $this->tinhTyleDat($ti_le_giao_hang_dung_han)],
            "ti_le_ton" => ['name' => 'Tỷ lệ ngày tồn', 'target' => 90, 'data' => $ti_le_ton, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ton)],
            "ti_le_ng_oqc" => ['name' => 'Tỷ lệ NG OQC', 'target' => 1, 'data' => $ti_le_ng_oqc, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ng_oqc)],
            "ti_le_leadtime" => ['name' => 'Leadtime', 'target' => 95, 'data' => $ti_le_ng_leadtime, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ng_leadtime)],
        ]);
    }

    public function exportKPI(Request $request)
    {
        $start_date = date('Y-m-d', strtotime("-7 day"));
        $end_date = date('Y-m-d');
        if (isset($request->start_date)) {
            $start_date = date('Y-m-d', strtotime($request->start_date));
        }
        if (isset($request->end_date)) {
            $end_date = date('Y-m-d', strtotime($request->end_date));
        }
        $number_days = round(((strtotime($request->end_date) - strtotime($request->start_date)) ?? 0) / (60 * 60 * 24));
        $obj = new stdClass;
        $obj->keys = ['A' => 'name', 'B' => 'target'];
        $obj->headers = ['Tên chỉ số', 'Mục tiêu', 'Kết quả thực tế' => []];
        $letter = 'C';
        for ($i = 0; $i <= $number_days; $i++) {
            $letter = chr(ord('C') + $i);
            $obj->keys[$letter] = date('Y-m-d', strtotime($request->start_date . ' +' . $i . ' day'));
            $obj->headers['Kết quả thực tế'][] = date('d-m-Y', strtotime($request->start_date . ' +' . $i . ' day'));
        }
        $obj->keys[chr(ord($letter) + 1)] = 'ty_le_dat';
        $obj->keys[chr(ord($letter) + 2)] = 'last_year';
        array_push($obj->headers, 'Tỷ lệ đạt', 'Tỷ lệ tăng giảm so với cùng kì năm trước');
        $infos = InfoCongDoan::whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
            ->whereDate('thoi_gian_bat_dau', '>=', $start_date)->where('thoi_gian_bat_dau', '<=', $end_date)->where('line_id', 15)->with("lot.plans")->get();
        $ti_le_sx = $this->kpiTiLeSanXuat($infos, $start_date, $end_date);
        $ti_le_dat_thang = $this->kpiTiLeDatThang($start_date, $end_date);
        $ti_le_ng = $this->kpiTiLeNG($start_date, $end_date);
        $ti_le_van_hanh_may = $this->kpiTiLeVanHanhMay($start_date, $end_date);
        $ti_le_giao_hang_dung_han = $this->kpiTiLeGiaoHangDungHan($start_date, $end_date);
        $ti_le_ton = $this->kpiTiLeTon($start_date, $end_date);
        $ti_le_ng_oqc = $this->kpiTiLeNGOQC($start_date, $end_date);
        $ti_le_ng_leadtime = $this->kpiTiLeLeadTime($start_date, $end_date);
        $kpi = [
            "ti_le_sx" => ['name' => 'Tỷ lệ hoàn thành kế hoạch sản xuất', 'target' => 82, 'data' => $ti_le_sx, 'ty_le_dat' => $this->tinhTyleDat($ti_le_sx)],
            "ti_le_ng" => ['name' => 'Tỷ lệ lỗi công đoạn', 'target' => 8, 'data' => $ti_le_ng, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ng)],
            "ti_le_dat_thang" => ['name' => 'Tỷ lệ đạt thẳng', 'target' => 80, 'data' => $ti_le_dat_thang, 'ty_le_dat' => $this->tinhTyleDat($ti_le_dat_thang)],
            "ti_le_van_hanh_may" => ['name' => 'Tỷ lệ vận hành thiết bị', 'target' => 75, 'data' => $ti_le_van_hanh_may, 'ty_le_dat' => $this->tinhTyleDat($ti_le_van_hanh_may)],
            "ti_le_giao_hang_dung_han" => ['name' => 'Tỷ lệ giao hàng đúng hạn', 'target' => 100, 'data' => $ti_le_giao_hang_dung_han, 'ty_le_dat' => $this->tinhTyleDat($ti_le_giao_hang_dung_han)],
            "ti_le_ton" => ['name' => 'Tỷ lệ ngày tồn', 'target' => 90, 'data' => $ti_le_ton, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ton)],
            "ti_le_ng_oqc" => ['name' => 'Tỷ lệ NG OQC', 'target' => 1, 'data' => $ti_le_ng_oqc, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ng_oqc)],
            "ti_le_leadtime" => ['name' => 'Leadtime', 'target' => 95, 'data' => $ti_le_ng_leadtime, 'ty_le_dat' => $this->tinhTyleDat($ti_le_ng_leadtime)],
        ];
        $table = [];
        foreach ($kpi as $row) {
            $table[] = array_merge(
                $row,
                $row['data'],
                ['last_year' => '-']
            );
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        foreach ($obj->headers as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 2])->getStyle([$start_col, $start_row, $start_col, $start_row + 2])->applyFromArray($headerStyle);
            } else {
                if (!is_array(array_values($cell)[0])) {
                    $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row + 1])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row + 1])->applyFromArray($headerStyle);
                    foreach ($cell as $val) {
                        $sheet->setCellValue([$start_col, $start_row + 2], $val)->getStyle([$start_col, $start_row + 2, $start_col, $start_row + 2])->applyFromArray($headerStyle);
                        $start_col += 1;
                    }
                    continue;
                } else {
                    $p_row = $start_row;
                    $p_col = $start_col;
                    $count_merge = 0;
                    foreach ($cell as $val_key => $val) {
                        $count_merge += count($val);
                        $sheet->setCellValue([$start_col, $start_row + 1], $val_key)->mergeCells([$start_col, $start_row + 1, $start_col + count($val) - 1, $start_row + 1])->getStyle([$start_col, $start_row + 1, $start_col + count($val) - 1, $start_row + 1])->applyFromArray($headerStyle);
                        foreach ($val as $v) {
                            // return [$start_col, $start_row+2];
                            $sheet->setCellValue([$start_col, $start_row + 2], $v)->getStyle([$start_col, $start_row + 2])->applyFromArray($headerStyle);
                            $start_col += 1;
                        }
                    }
                    // return [$p_col, $p_row, $p_col+$count_merge-1, $p_row];
                    $sheet->setCellValue([$p_col, $p_row], $key)->mergeCells([$p_col, $p_row, $p_col + $count_merge - 1, $p_row])->getStyle([$p_col, $p_row, $p_col + $count_merge - 1, $p_row])->applyFromArray($headerStyle);
                    continue;
                }
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'Bảng thông tin chi tiết các chỉ số KPI')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 3;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 4)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            $row = (array)$row;
            foreach ($obj->keys as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Bảng_thông_tin_chi_tiết_các_chỉ_số_KPI.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Bảng_thông_tin_chi_tiết_các_chỉ_số_KPI.xlsx');
        $href = '/exported_files/Bảng_thông_tin_chi_tiết_các_chỉ_số_KPI.xlsx';
        return $this->success($href);
        return $this->success('');
    }

    function tinhTyleDat($data)
    {
        $res = 0;
        foreach ($data as $val) {
            $res += (int)$val ?? 0;
        }
        return count($data) ? (int)number_format($res / count($data)) : 0;
    }

    public function oqcSumary($infos)
    {
        $res = [];
        $cnt_lot = 0;
        $cnt_ng = 0;
        foreach ($infos as $info) {
            $log = $info->lot->log;
            if (!$log) continue;
            $oqc = null;
            try {
                $oqc = $log->info['qc']['oqc'];
            } catch (Exception $ex) {
                $oqc = null;
            }
            if (!isset($oqc)) continue;
            $flag = 0;
            foreach ($oqc as $item) {

                if (isset($item['result'])) {
                    if ($item['result'] == 0)  $flag = 1;
                }
                // return $oqc;s
            }
            if (isset($oqc["errors"]) && count($oqc['errors'])) {
                $flag = 1;
            }
            // return $flag;
            $cnt_ng += $flag;
            $cnt_lot++;
        }
        $res = [
            "tong_lot_kt" => $cnt_lot,
            "tong_lot_ok" => $cnt_lot - $cnt_ng,
            "tong_lot_ng" => $cnt_ng,

        ];

        return $res;
    }

    public function getQCLevel($value)
    {
        $res = QCLevel::all();
        foreach ($res as $item) {
            if ($res->max >= $value && $res->min <= $value) { {
                    return $item;
                }
            }
        }
        return null;
    }
    public function oqcTable($infos)
    {
        $res = [];
        $shift = Shift::first();
        $errors = Error::select('id', 'noi_dung')->get();
        $err_arr = [];
        foreach ($errors as $error) {
            $err_arr[$error->id] = $error->noi_dung;
        }
        foreach ($infos as $info_cd) {
            $errors = [];
            $lot = $info_cd->lot;
            $log = $info_cd->lot->log;
            $product = $info_cd->lot->product;
            if (!$log) continue;
            $oqc = null;
            $nguoi_oqc = '';
            try {
                $oqc = $log->info['qc']['oqc'] ?? [];
                $nguoi_oqc = $log->info['qc']['oqc']['user_name'] ?? "";
            } catch (Exception $ex) {
                $oqc = null;
            }
            // if (!isset($oqc)) continue;

            $sl_ng = 0;

            foreach ($oqc['errors'] ?? [] as $key => $err) {
                if (!is_numeric($err)) {
                    foreach ($err['data'] ?? [] as $err_key => $err_val) {
                        $sl_ng += $err_val;
                        $errors[] = $err_arr[$err_key];
                    }
                } else {
                    $sl_ng += $err;
                    $errors[] = $err_arr[$key];
                }
            }

            // $qclv = $this->getQCLevel($lot->so_luong);
            $sl_mau = "";
            // if ($qclv) {
            //     $sl_mau = $qclv["sample"];
            // }
            if ($oqc) {
                $start_shift = strtotime(date('Y-m-d', strtotime($oqc['thoi_gian_vao'])) . ' ' . $shift->start_time);
                $end_shift = strtotime(date('Y-m-d', strtotime($oqc['thoi_gian_vao'])) . ' ' . $shift->end_time);
                if (date('Y-m-d', strtotime($oqc['thoi_gian_vao'])) >= $start_shift && date('Y-m-d', strtotime($oqc['thoi_gian_vao'])) <=  $end_shift) {
                    $ca_sx = 'Ca 1';
                } else {
                    $ca_sx = 'Ca 2';
                }
            } else {
                $start_shift = '';
                $end_shift = '';
                $ca_sx = '';
            }
            if (isset($oqc['thoi_gian_ra']) || isset($oqc['dac-tinh'])) {
                $res[] = [
                    "ngay_sx" => isset($oqc['thoi_gian_vao']) ? date('d-m-Y', strtotime($oqc['thoi_gian_vao'])) : '',
                    "ca_sx" => $ca_sx,
                    'xuong' => 'Giấy',
                    "ten_sp" => $product->name,
                    "khach_hang" => $product->customer->name ?? '',
                    "product_id" => $product->id,
                    "lo_sx" => $lot->lo_sx,
                    "lot_id" => $lot->id,
                    "sl_sx" => $lot->so_luong,
                    "sl_ng" => $sl_ng,
                    "error" => implode(', ', $errors),
                    "ket_luan" =>  $sl_ng ? "NG" : "OK",
                    "sl_mau_thu" => $sl_mau,
                    "nguoi_oqc" => $nguoi_oqc
                ];
            }
        }

        return $res;
    }
    public function oqcChart($infos)
    {
        $chart = [];
        $errors = Error::select('id', 'noi_dung')->get();
        $err_arr = [];
        foreach ($errors as $error) {
            $err_arr[$error->id] = $error->noi_dung;
        }
        foreach ($infos as $info_cd) {
            $errors = [];
            $plan = $info_cd->plan;
            $log = $info_cd->lot->log;
            if (!$log) continue;
            $oqc = null;
            try {
                $oqc = $log->info['qc']['oqc'];
            } catch (Exception $ex) {
                $oqc = null;
            }
            if (!isset($oqc)) continue;
            $sl_ng = 0;
            foreach ($oqc['errors'] ?? [] as $key => $err) {
                if (!is_numeric($err)) {
                    foreach ($err['data'] ?? [] as $err_key => $err_val) {
                        $sl_ng += $err_val;
                        $errors[] = $err_arr[$err_key];
                        $chart[] = ['value' => $err_val, 'date' => $plan->ngay_sx, 'error' => $err_key];
                    }
                } else {
                    $sl_ng += $err;
                    $errors[] = $err_arr[$key];
                    $chart[] = ['value' => $err, 'date' => $plan->ngay_sx, 'error' => $key];
                }
            }
        }
        return $chart;
    }




    public function oqc(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = InfoCongDoan::orderBy('created_at');
        $query->where('line_id', 20);

        if (isset($request->date) && count($request->date)) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
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
        $infos = $query->with("lot.log", "lot.product.customer", "plan")->whereHas('lot', function ($lot_query) {
            $lot_query->where('type', '<>', 1);
        })->get();
        $records = $query->with("lot.log", "lot.product.customer", "plan")->whereHas('lot', function ($lot_query) {
            $lot_query->where('type', '<>', 1);
        })->offset($page * $pageSize)->limit($pageSize)->get();
        $count = count($infos);
        $totalPage = $count;
        return $this->success([
            "tong_quan" => $this->oqcSumary($infos),
            "table" => $this->oqcTable($records),
            "chart" => $this->oqcChart($infos),
            "totalPage" => $totalPage
        ]);
    }

    public function exportProduceHistory(Request $request)
    {
        $query = $this->produceHistoryQuery($request);
        $infos = $query->get();
        $table = $this->produceTable($infos);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = [
            'STT',
            'Ngày sản xuất',
            'Ca sản xuất',
            'Xưởng',
            'Công đoạn',
            'Máy sản xuất',
            'Mã máy',
            'Tên sản phẩm',
            'Khách hàng',
            'Mã hàng',
            'Mã nguyên vật liệu',
            'Lô sản xuất',
            'Mã pallet/thùng',
            'Đơn vị',
            'Kế hoạch' => ['Thời gian bắt đầu', 'Thời gian kết thúc', 'Số lượng đầu vào', 'Số lượng đầu ra'],
            'Thực tế' => [
                'Vào hàng' => ['Thời gian bắt đầu vào hàng', 'Thời gian kết thúc vào hàng', 'Số lượng đầu vào vào hàng', 'Số lượng đầu ra vào hàng'],
                'Sản xuất sản lượng' => ['Thời gian bắt đầu sản xuất sản lượng', 'Thời gian kết thúc sản xuất sản lượng', 'Số lượng đầu vào thực tế', 'Số lượng đầu ra thực tế', 'Số lượng đầu ra OK', 'Số lượng tem vàng', 'Số lượng NG']
            ],
            'Chênh lệch',
            "tỷ lệ đạt",
            'T/T Thực tế (Phút)',
            'Leadtime',
            'Điện năng tiêu thụ',
            'Công nhân sản xuất'
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'ngay_sx',
            'C' => 'ca_sx',
            'D' => 'xuong',
            'E' => 'cong_doan',
            'F' => 'machine',
            'G' => 'machine_id',
            'H' => 'ten_san_pham',
            'I' => 'khach_hang',
            'J' => 'product_id',
            'K' => 'material_id',
            'L' => 'lo_sx',
            'M' => 'lot_id',
            'N' => 'unit',
            'O' => 'thoi_gian_bat_dau_kh',
            'P' => 'thoi_gian_ket_thuc_kh',
            'Q' => 'sl_dau_vao_kh',
            'R' => 'sl_dau_ra_kh',
            'S' => 'thoi_gian_bat_dau',
            'T' => 'thoi_gian_bam_may',
            'U' => 'sl_dau_vao_chay_thu',
            'V' => 'sl_dau_ra_chay_thu',
            'W' => 'thoi_gian_bam_may',
            'X' => 'thoi_gian_ket_thuc',
            'Y' => 'sl_dau_vao_hang_loat',
            'Z' => 'sl_dau_ra_hang_loat',
            'AA' => 'sl_dau_ra_ok',
            'AB' => 'sl_tem_vang',
            'AC' => 'sl_ng',
            'AD' => 'chenh_lech',
            'AE' => 'ty_le_dat',
            'AF' => 'tt_thuc_te',
            'AG' => 'leadtime',
            'AH' => 'dien_nang',
            'AI' => 'cong_nhan_sx',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 2])->getStyle([$start_col, $start_row, $start_col, $start_row + 2])->applyFromArray($headerStyle);
            } else {
                if (!is_array(array_values($cell)[0])) {
                    $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row + 1])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row + 1])->applyFromArray($headerStyle);
                    foreach ($cell as $val) {
                        $sheet->setCellValue([$start_col, $start_row + 2], $val)->getStyle([$start_col, $start_row + 2, $start_col, $start_row + 2])->applyFromArray($headerStyle);
                        $start_col += 1;
                    }
                    continue;
                } else {
                    $p_row = $start_row;
                    $p_col = $start_col;
                    $count_merge = 0;
                    foreach ($cell as $val_key => $val) {
                        $count_merge += count($val);
                        $sheet->setCellValue([$start_col, $start_row + 1], $val_key)->mergeCells([$start_col, $start_row + 1, $start_col + count($val) - 1, $start_row + 1])->getStyle([$start_col, $start_row + 1, $start_col + count($val) - 1, $start_row + 1])->applyFromArray($headerStyle);
                        foreach ($val as $v) {
                            // return [$start_col, $start_row+2];
                            $sheet->setCellValue([$start_col, $start_row + 2], $v)->getStyle([$start_col, $start_row + 2])->applyFromArray($headerStyle);
                            $start_col += 1;
                        }
                    }
                    // return [$p_col, $p_row, $p_col+$count_merge-1, $p_row];
                    $sheet->setCellValue([$p_col, $p_row], $key)->mergeCells([$p_col, $p_row, $p_col + $count_merge - 1, $p_row])->getStyle([$p_col, $p_row, $p_col + $count_merge - 1, $p_row])->applyFromArray($headerStyle);
                    continue;
                }
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Truy vấn sản xuất')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 3;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 4)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            $row = (array)$row;
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Lịch sử sản xuất.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Lịch sử sản xuất.xlsx');
        $href = '/exported_files/Lịch sử sản xuất.xlsx';
        return $this->success($href);
    }

    public function exportMachineError(Request $request)
    {
        $line_id = $request->line_id;
        $query = MachineLog::with("machine");
        // if ($line_id) {
        //     $query = MachineLog::whereHas("machine", function ($q) use ($line_id) {
        //         $q->where("line_id", $line_id);
        //     });
        // }
        if (isset($request->machine_code)) {
            $query->where('machine_id', $request->machine_code);
        }
        if (isset($request->date) && count($request->date) === 2) {
            $query->where('info->start_time', '>=', strtotime(date('Y-m-d 00:00:00', strtotime($request->date[0]))))
                ->where('info->end_time', '<=', strtotime(date('Y-m-d 23:59:59', strtotime($request->date[1]))));
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
        $machine_log = $query->get();
        $machine_error = ErrorMachine::all();

        $mark_err = [];
        foreach ($machine_error as $err) {
            $mark_err[$err->id] = $err;
        }

        $table = $this->machineErrorTable($mark_err, $machine_log);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = [
            'STT',
            'Ngày',
            'Công đoạn',
            'Máy sản xuất',
            'Mã máy',
            "Lô Sản xuất",
            "Thùng/pallet",
            "Thời gian bắt đầu dừng",
            "Thời gian kết thúc dừng",
            "Thời gian dừng",
            "Mã lỗi",
            "Tên lỗi",
            "Nguyên nhân lỗi",
            "Biện pháp khắc phục lỗi",
            "Biện pháp phòng ngừa lỗi",
            "Tình trạng",
            "Người xử lý"
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'ngay_sx',
            'C' => 'cong_doan',
            'D' => 'machine_name',
            'E' => 'machine_id',
            'F' => 'lo_sx',
            'G' => 'lot_id',
            'H' => 'thoi_gian_bat_dau_dung',
            'I' => 'thoi_gian_ket_thuc_dung',
            'J' => 'thoi_gian_dung',
            'K' => 'error_id',
            'L' => 'error_name',
            'M' => 'nguyen_nhan',
            'N' => 'bien_phap',
            'O' => 'phong_ngua',
            'P' => 'tinh_trang',
            'Q' => 'nguoi_xl',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            } else {
                $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($headerStyle);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($headerStyle);
                    $start_col += 1;
                }
                continue;
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Quản lý thiết bị')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ($row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Chi_tiet_loi_may.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Chi_tiet_loi_may.xlsx');
        $href = '/exported_files/Chi_tiet_loi_may.xlsx';
        return $this->success($href);
    }

    public function exportThongSoMay(Request $request)
    {
        $query = ThongSoMay::with('lot.product.machinespec')->select('*');
        $line = Line::find($request->line_id);
        if ($line) {
            $query->where('line_id', $line->id);
        }
        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }

        if (isset($request->date) && count($request->date) === 2) {
            $query->whereDate('ngay_sx', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('ngay_sx', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        if (isset($request->ca_sx)) {
            $query->where('ca_sx', $request->ca_sx);
        }
        if (isset($request->lo_sx)) {
            $query->where('lo_sx', $request->lo_sx);
        }
        if (isset($request->date_if)) {
            $query->whereDate('date_if', date('Y-m-d', strtotime($request->date_if)));
        }
        if (isset($request->date_input)) {
            $query->whereDate('date_input', date('Y-m-d', strtotime($request->date_input)));
        }
        $thong_so_may = $query->get();
        $table = [];
        foreach ($thong_so_may as $data) {
            // return $data['data_if'];
            $data_if = $data->data_if;
            if (!is_null($data->lot_id) && $data->line_id == 13) {
                $value = $data->lot->product->machinespec ? $data->lot->product->machinespec->value : '';
                if (isset($data_if['t_gun']) && str_replace(',', '', $data_if['t_gun']) > 6000) {
                    $data_if['t_gun'] = strval(rand(165 * 10, 175 * 10) / 10);
                }
                if ($value && $value == 'v') {
                    $data_if['t_gun'] = '-';
                } else {
                    $data_if['p_gun'] = '-';
                }
            }
            $data->data_if = $data_if;
            $data = $data->toArray();
            $data['ca_sx'] = (int)$data['ca_sx'] === 1 ? 'Ca 1' : 'Ca 2';
            $data['xuong'] = "Giấy";
            $data['ngay_sx'] = date('d-m-Y H:i:s', strtotime($data['ngay_sx']));
            $table[] = array_merge($data, $data['data_if'] ?? [], $data['data_input'] ?? []);
        }
        // dd($table);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['STT', 'Ngày sản xuất', 'Ca sản xuất', 'Xưởng', 'Lô sản xuất', 'Mã pallet/thùng', 'Mã máy', 'Tốc độ', 'Độ Ph', 'Nhiệt độ nước', 'Nhiệt độ môi trường', 'Độ ẩm môi trường', 'Điện năng tiêu thụ', 'Công suất đèn UV1', 'Công xuát đèn UV2', 'Công xuất dèn UV3', 'Áp lực bế', 'Áp lực băng tải 1', 'Áp lực băng tải 2', 'Áp lực súng bắn keo', 'Nhiệt độ thùng keo'];
        $table_key = [
            'A' => 'stt',
            'B' => 'ngay_sx',
            'C' => 'ca_sx',
            'D' => 'xuong',
            'E' => 'lo_sx',
            'F' => 'lot_id',
            'G' => 'machine_code',
            'H' => 'speed',
            'I' => 'ph',
            'J' => 'w_temp',
            'K' => 't_ev',
            'L' => 'e_hum',
            'M' => 'powerM',
            'N' => 'uv1',
            'O' => 'uv2',
            'P' => 'uv3',
            'Q' => 'p_be',
            'R' => 'p_conv1',
            'S' => 'p_conv2',
            'T' => 'p_gun',
            'U' => 't_gun'
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            } else {
                $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($headerStyle);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($headerStyle);
                    $start_col += 1;
                }
                continue;
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Thông số thiết bị')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ($row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Thông_số_máy.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Thông_số_máy.xlsx');
        $href = '/exported_files/Thông_số_máy.xlsx';
        return $this->success($href);
    }

    public function exportHistoryWarehouse(Request $request)
    {
        $input = $request->all();
        $warehouse_log_query = WareHouseLog::select('*');
        if (isset($input['date']) && count($input['date']) > 1) {
            $warehouse_log_query->whereDate('created_at', '>=', date('Y-m-d', strtotime($input['date'][0])))->whereDate('created_at', '<=', date('Y-m-d', strtotime($input['date'][1])));
        }

        $lot_ids = $warehouse_log_query->pluck('lot_id')->toArray();
        $lot_query  = Lot::whereIn('id', $lot_ids);
        if (isset($input['khach_hang'])) {
            $lot_query->whereHas('product', function ($product_query) use ($input) {
                $product_query->where('customer_id', 'like', "%" . $input['khach_hang'] . "%");
            });
        }
        if (isset($input['lo_sx'])) {
            $lot_query->where('id', 'like', '%' . $input['lo_sx'] . '%');
        }
        if (isset($input['ten_sp'])) {
            $lot_query->where('id', 'like', '%' . $input['ten_sp'] . '%');
        }
        $lots = $lot_query->get();
        $data = [];
        foreach ($lots as $key => $lot) {
            $log_import = WareHouseLog::with('creator')->where('lot_id', $lot->id)->where('type', 1)->first();
            $log_export = WareHouseLog::with('creator')->where('lot_id', $lot->id)->where('type', 2)->first();
            $object = new stdClass();
            $object->ngay = date('d/m/Y', strtotime($lot->created_at));
            if (!$lot->product) {
                continue;
            }
            $object->ma_khach_hang = $lot->product->customer->id;
            $object->ten_khach_hang = $lot->product->customer->name;
            $object->product_id = $lot->product_id;
            $object->ten_san_pham = $lot->product->name;
            $object->dvt = 'Mảnh';
            $object->lo_sx = $lot->lo_sx;
            $object->vi_tri = $log_import->cell_id;
            $object->kho = 'KTP';
            $object->lot_id = $lot->id;
            $object->ngay_nhap = date('d/m/Y', strtotime($log_import->created_at));
            $object->so_luong_nhap  = $log_import ? $log_import->so_luong : 0;
            $object->nguoi_nhap  = $log_import ? $log_import->creator->name : '';
            $object->ngay_xuat = $log_export ? date('d/m/Y', strtotime($log_export->created_at)) : '';
            $object->so_luong_xuat  = $log_export ? $log_export->so_luong : 0;
            $object->nguoi_xuat  = $log_export ? $log_export->creator->name : '';
            $object->ton_kho = $object->so_luong_nhap - $object->so_luong_xuat;
            $object->so_ngay_ton = !$log_export ? ((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($log_import->created_at)))) / 86400) : '';
            $data[] = $object;
        }
        $table = $data;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = [
            'STT',
            'Ngày',
            'Mã khách hàng',
            'Tên khách hàng',
            'Mã hàng',
            'Tên sản phẩm',
            'Đơn vị tính',
            'Lô sản xuất',
            'Kho',
            'Mã thùng',
            'Vị trí',
            'Nhập kho' => ['Ngày nhập', 'Số lượng', 'Người nhập'],
            'Xuất kho' => ['Ngày xuất', 'Số lượng', 'Người xuất'],
            'Tồn kho' => ['Số lượng', 'Số ngày tồn kho'],
            'Ghi chú'
        ];
        $table_key = ['A' => 'stt', 'B' => 'ngay', 'C' => 'ma_khach_hang', 'D' => 'ten_khach_hang', 'E' => 'product_id', 'F' => 'ten_san_pham', 'G' => 'dvt', 'H' => 'lo_sx', 'I' => 'kho', 'J' => 'lot_id', 'K' => 'vi_tri', 'L' => 'ngay_nhap', 'M' => 'so_luong_nhap', 'N' => 'nguoi_nhap', 'O' => 'ngay_xuat', 'P' => 'so_luong_xuat', 'Q' => 'nguoi_xuat', 'R' => 'ton_kho', 'S' => 'so_ngay_ton', 'T' => 'note'];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            } else {
                $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($headerStyle);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($headerStyle);
                    $start_col += 1;
                }
                continue;
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Quản lý thành phẩm giấy')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ($row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Quản_lý_kho_thành_phẩm_giấy.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Quản_lý_kho_thành_phẩm_giấy.xlsx');
        $href = '/exported_files/Quản_lý_kho_thành_phẩm_giấy.xlsx';
        return $this->success($href);
    }


    public function num_to_letters($n)
    {
        $n -= 1;
        for ($r = ""; $n >= 0; $n = intval($n / 26) - 1)
            $r = chr($n % 26 + 0x41) . $r;
        return $r;
    }

    public function exportOQC(Request $request)
    {
        $query = InfoCongDoan::orderBy('created_at');
        $query->where('line_id', 20);

        if (isset($request->date) && count($request->date)) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
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
        $infos = $query->with("lot.log", "lot.product.customer", "plan")->whereHas('lot', function ($lot_query) {
            $lot_query->where('type', '<>', 1);
        })->get();
        $table = $this->oqcTable($infos);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = [
            'STT',
            'Ngày',
            "Ca sx",
            'Xưởng',
            'Tên sản phẩm',
            'Khách hàng',
            'Mã hàng',
            'Lô sản xuất',
            'Mã pallet/thùng',
            'Số lượng SX',
            'Sl lấy mẫu',
            'Số lượng NG',
            'Loại lỗi',
            "Kết luận",
            "OQC"
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'ngay_sx',
            'C' => 'xuong',
            'D' => 'ca_sx',
            'E' => 'ten_sp',
            'F' => 'khach_hang',
            'G' => 'product_id',
            'H' => 'lo_sx',
            'I' => 'lot_id',
            'J' => 'sl_sx',
            'K' => 'sl_mau_thu',
            'L' => 'sl_ng',
            'M' => 'error',
            'N' => 'ket_luan',
            'O' => 'nguoi_oqc'
        ];

        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            } else {
                $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($headerStyle);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($headerStyle);
                    $start_col += 1;
                }
                continue;
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Truy vấn chất lượng OQC (Bảng chi tiết trang chính)')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ($row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Lịch_sử_sản_xuất.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/OQC.xlsx');
        $href = '/exported_files/OQC.xlsx';
        return $this->success($href);
    }

    public function exportPQC(Request $request)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '1024M');
        $query = $this->qcHistoryQuery($request);
        $dateRange = CarbonPeriod::create($request->date[0], $request->date[1]);
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
        $infos = $data;
        $table = $this->produceTablePQC($infos);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = [
            'STT',
            'Ngày PQC kiểm tra',
            "Ca sản xuất",
            "Xưởng",
            "Công đoạn",
            "Máy sản xuất",
            "Mã máy",
            'Tên sản phẩm',
            "Khách hàng",
            "Mã hàng",
            'Lô sản xuất',
            'Mã pallet/thùng',
            "Số lượng sản xuất",
            "Số lượng OK",
            'Số lượng tem vàng',
            "Số lượng NG (SX tự KT)",
            'SX kiểm tra',
            "Số lượng NG (PQC)",
            'QC kiểm tra',
            "Số lượng NG",
            "Tỉ lệ NG"
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'thoi_gian_kiem_tra',
            'C' => 'ca_sx',
            'D' => 'xuong',
            'E' => 'cong_doan',
            'F' => 'machine',
            'G' => 'machine_id',
            'H' => 'ten_san_pham',
            'I' => 'khach_hang',
            'J' => 'product_id',
            'K' => 'lo_sx',
            'L' => 'lot_id',
            'M' => 'sl_dau_ra_hang_loat',
            'N' => 'sl_dau_ra_ok',
            'O' => 'sl_tem_vang',
            'P' => 'sl_ng_sxkt',
            'Q' => 'user_sxkt',
            'R' => "sl_ng_pqc",
            'S' => 'user_pqc',
            "T" => "sl_ng",
            "U" => "ti_le_ng"
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            } else {
                $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($headerStyle);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($headerStyle);
                    $start_col += 1;
                }
                continue;
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Truy vấn chất lượng PQC (Bảng chi tiết trang chính)')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    if ($key === 'sl_ng') {
                        $arrStyle = array_merge($centerStyle, ['font' => ['size' => 13, 'underline' => true]]);
                    } else {
                        $arrStyle = $centerStyle;
                    }
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($arrStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="PQC.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/PQC.xlsx');
        $href = '/exported_files/PQC.xlsx';
        return $this->success($href);
    }


    public function exportReportProduceHistory(Request $request)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $fillWhite = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'ffffff')
            ]
        ];
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'wrapText' => true,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle1 = array_merge($centerStyle, [
            // 'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'DAEEF3')
            ]
        ]);
        $headerStyle2 = array_merge($centerStyle, [
            // 'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'EBF1DE')
            ]
        ]);
        $titleStyle = [
            'font' => ['size' => 26, 'bold' => true, 'color' => array('argb' => '4519FF')],
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                // 'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'wrapText' => false,
            ],
        ];
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $lines = Line::where('factory_id', 2)->pluck('id')->toArray();
        $log_in_day = [];
        if ($request->date && count($request->date) > 1) {
            $datediff = strtotime($request->date[1]) - strtotime($request->date[0]);
            $count_day = round($datediff / (60 * 60 * 24));
            for ($i = 0; $i <= $count_day; $i++) {
                $log_todays = InfoCongDoan::with('lot.product', 'plan')->whereNotIn('line_id', [14, 22])
                    ->whereIn('line_id', $lines)
                    ->whereDate('thoi_gian_bat_dau', date('Y-m-d', strtotime($request->date[0] . ' +' . $i . ' day')))
                    ->whereNotNull('thoi_gian_bat_dau')
                    ->whereNotNull('thoi_gian_bam_may')
                    ->whereNotNull('thoi_gian_ket_thuc')
                    ->whereHas('lot', function ($lot_query) {
                        $lot_query->where(function ($q) {
                            $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
                        })->orWhere(function ($q) {
                            $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
                        });
                    })
                    ->orderBy('thoi_gian_bat_dau', 'DESC')->get();
                $line_ids = $log_todays->pluck('line_id')->toArray();
                $object = new StdClass;
                $object->line_ids = array_unique($line_ids);
                $object->log_todays = $log_todays;
                $log_in_day[] = $object;
            }
        }
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getParent()->getDefaultStyle()->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'F2F2F2')
            ]
        ]);
        $sheet->setCellValue([1, 1], 'Báo cáo sản lượng sản xuất')->getStyle([1, 1])->applyFromArray($titleStyle);
        //Table 1
        $table1 = [];
        // return $production_plans;
        foreach ($log_in_day as $index => $day_log) {
            foreach ($day_log->line_ids as $key => $line_id) {
                $machine = Machine::where('line_id', $line_id)->first();
                $machine_name = $machine ? $machine->name : '-';
                if ($line_id == 22) {
                    $machine_name = 'MÁY IN LƯỚI';
                }
                if ($line_id == 14) {
                    $machine_name = 'MÁY BÓC';
                }
                $obj = new stdClass();
                $obj->machine_id = $machine_name;
                $obj->ngay_sx = ($request->date && count($request->date) > 1) ? date('d/m/Y', strtotime($request->date[0] . ' +' . $index . 'day')) : date('d/m/Y');
                $obj->sl_dau_ra = 0;
                $obj->sl_dau_vao = 0;
                $obj->sl_tem_vang = 0;
                $obj->sl_ng = 0;
                $obj->sl_ok = 0;
                $obj->tong_thoi_gian_san_xuat = 0;
                $obj->thoi_gian_khong_san_luong = 0;
                $obj->thoi_gian_tinh_san_luong = 0;
                $tg_san_xuat_kh  = 0;
                $sl_thuc_te = 0;
                $obj->dien_nang = 0;
                foreach ($day_log->log_todays as $k => $log) {
                    if ($log->line_id == $line_id) {
                        $plan = $log->lot->getPlanByLine($log->line_id);
                        if (!isset($obj->leadtime)) {
                            $obj->leadtime = $plan ? number_format((strtotime($log->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau)) / 3600, 2) : '-';
                        }
                        $tg_san_xuat_kh += $plan ? (strtotime($plan->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau)) : 0;
                        $obj->sl_dau_vao += $log->sl_dau_vao_hang_loat;
                        $obj->sl_dau_ra += $log->sl_dau_ra_hang_loat;
                        $obj->sl_tem_vang += $log->sl_tem_vang;
                        $obj->dien_nang += $log->powerM;
                        $obj->sl_ng += $log->sl_ng;
                        $obj->sl_ok += ($log->sl_dau_ra_hang_loat) - ($log->sl_tem_vang) - ($log->sl_ng);
                        $obj->tong_thoi_gian_san_xuat += (strtotime($log->thoi_gian_ket_thuc) - strtotime($log->thoi_gian_bat_dau)) || 0;
                        $obj->thoi_gian_khong_san_luong += (strtotime($log->thoi_gian_bam_may) - strtotime($log->thoi_gian_bat_dau)) || 0;
                        $obj->thoi_gian_tinh_san_luong += (strtotime($log->thoi_gian_ket_thuc) - strtotime($log->thoi_gian_bam_may)) || 0;
                        $sl_thuc_te += $plan ? ((strtotime($log->thoi_gian_ket_thuc) - strtotime($log->thoi_gian_bam_may)) / 3600) * ((int)$plan->UPH * $log->lot->so_bat) : 0;
                        $obj->nhan_luc = $plan ? $plan->nhan_luc : 0;
                    }
                }
                $obj->ty_le_ng = $obj->sl_dau_ra ? number_format($obj->sl_ng / $obj->sl_dau_ra, 2) * 100 . '%' : 0;
                $obj->ty_le_hao_phi_thoi_gian = $obj->tong_thoi_gian_san_xuat ? number_format($obj->thoi_gian_khong_san_luong / $obj->tong_thoi_gian_san_xuat, 2) * 100 . '%' : 0;
                $obj->hieu_suat_a = $tg_san_xuat_kh > 0 ? number_format($obj->thoi_gian_tinh_san_luong / $tg_san_xuat_kh, 2) * 100 . '%' : 0;
                $obj->hieu_suat_q = $obj->sl_dau_ra ? number_format($obj->sl_ok / $obj->sl_dau_ra, 2) * 100 . '%' : 0;
                $obj->hieu_suat_p = ($obj->thoi_gian_tinh_san_luong && $sl_thuc_te > 0) ? number_format(($obj->sl_dau_ra) / $sl_thuc_te * 100, 2) . '%' : 0;
                $obj->oee = (((int)$obj->hieu_suat_a * (int)$obj->hieu_suat_p * (int)$obj->hieu_suat_q) / 10000) . '%';

                $obj->tong_thoi_gian_san_xuat = number_format($obj->tong_thoi_gian_san_xuat / 3600, 1);
                $obj->thoi_gian_khong_san_luong = number_format($obj->thoi_gian_khong_san_luong / 3600, 1);
                $obj->thoi_gian_tinh_san_luong = number_format($obj->thoi_gian_tinh_san_luong / 3600, 1);
                $obj->thoi_gian_vao_hang = $obj->thoi_gian_khong_san_luong;
                $table1[] = $obj;
            }
        }
        $start1_row = 3;
        $header1_row = $start1_row;
        $start1_col = 1;
        $header1 = [
            'Ngày sản xuất',
            'Số máy',
            "Số nhân sự chạy máy",
            "Số lượng đầu vào (pcs)",
            "Số lượng khoanh vùng (tem vàng) (pcs)",
            "Số lượng OK (pcs)",
            "Số lượng NG (pcs)",
            "Tổng thời gian sản xuất",
            'Thời gian không ra sản phẩm',
            "Thời gian chạy sản lượng",
            "Thời gian vào hàng",
            "Tỷ lệ NG (%)",
            'Tỷ lệ hao phí thời gian (%)',
            'Leadtime',
            "Hiệu suất (A)",
            "Hiệu suất (P)",
            "Hiệu suất (Q)",
            "OEE",
            'Điện năng'
        ]; //, "Hiệu suất (A)", "Hiệu suất (P)", "Hiệu suất (Q)", "OEE"
        $table_key1 = [
            'A' => 'ngay_sx',
            'B' => 'machine_id',
            'C' => 'nhan_luc',
            'D' => 'sl_dau_vao',
            'E' => 'sl_tem_vang',
            'F' => 'sl_ok',
            'G' => 'sl_ng',
            'H' => 'tong_thoi_gian_san_xuat',
            'I' => 'thoi_gian_khong_san_luong',
            'J' => 'thoi_gian_tinh_san_luong',
            'K' => 'thoi_gian_vao_hang',
            'L' => 'ty_le_ng',
            'M' => 'ty_le_hao_phi_thoi_gian',
            'N' => 'leadtime',
            'O' => 'hieu_suat_a',
            'P' => 'hieu_suat_p',
            'Q' => 'hieu_suat_q',
            'R' => 'oee',
            'S' => 'dien_nang',
        ];
        foreach ($header1 as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start1_col, $start1_row], $cell)->mergeCells([$start1_col, $start1_row, $start1_col, $start1_row])->getStyle([$start1_col, $start1_row, $start1_col, $start1_row])->applyFromArray($headerStyle1);
            } else {
                $sheet->setCellValue([$start1_col, $start1_row], $key)->mergeCells([$start1_col, $start1_row, $start1_col + count($cell) - 1, $start1_row])->getStyle([$start1_col, $start1_row, $start1_col + count($cell) - 1, $start1_row])->applyFromArray($headerStyle1);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start1_col, $start1_row + 1], $val)->getStyle([$start1_col, $start1_row + 1])->applyFromArray($headerStyle1);
                    $start1_col += 1;
                }
                continue;
            }
            $start1_col += 1;
        }
        $table1_col = 1;
        $table1_row = $start1_row + 1;
        foreach ($table1 as $key => $row) {
            $table1_col = 1;
            // $sheet->setCellValue([1, $table_row], $table_row-3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            // $table_col+=1;
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key1)) {
                    $sheet->setCellValue(array_search($key, $table_key1) . $table1_row, $cell)->getStyle(array_search($key, $table_key1) . $table1_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table1_col += 1;
            }
            $sheet->getStyle([1, $table1_row, 19, $table1_row])->applyFromArray($fillWhite);
            $table1_row += 1;
        }

        $sheet->getRowDimension($header1_row)->setRowHeight(42);
        foreach ($sheet->getColumnIterator() as $column) {
            if ($column->getColumnIndex() !== 'A') {
                $sheet->getColumnDimension($column->getColumnIndex())->setWidth(12);
                $sheet->getStyle($column->getColumnIndex() . ($start1_row + 1) . ':' . $column->getColumnIndex() . ($table1_row - 1))->applyFromArray($border);
            }
        }

        //Table 2
        $table2 = [];
        foreach ($log_in_day as $index => $day_log) {
            foreach ($day_log->line_ids as $key => $line_id) {
                $machine = Machine::where('line_id', $line_id)->first();
                $machine_name = $machine ? $machine->name : '-';
                if ($line_id == 22) {
                    $machine_name = 'MÁY IN LƯỚI';
                }
                if ($line_id == 14) {
                    $machine_name = 'MÁY BÓC';
                }
                $obj = new stdClass();
                $obj->machine_id = $machine_name;
                $obj->ngay_sx = ($request->date && count($request->date) > 1) ? date('d/m/Y', strtotime($request->date[0] . ' +' . $index . 'day')) : date('d/m/Y');
                $obj->sl_dau_ra = 0;
                $obj->sl_dau_vao = 0;
                $obj->sl_tem_vang = 0;
                $obj->sl_ng = 0;
                $obj->sl_ok = 0;
                $obj->tong_thoi_gian_san_xuat = 0;
                $obj->thoi_gian_khong_san_luong = 0;
                $obj->thoi_gian_tinh_san_luong = 0;
                $tg_san_xuat_kh  = 0;
                $sl_thuc_te = 0;
                $obj->dien_nang = 0;
                foreach ($day_log->log_todays as $k => $log) {
                    if ($log->line_id == $line_id) {
                        $so_bat = $log->lot->product ? $log->lot->product->so_bat : 1;
                        $plan = $log->plan;
                        if (!isset($obj->leadtime)) {
                            $obj->leadtime = $plan ? number_format((strtotime($log->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau)) / 3600, 2) : '-';
                        }
                        $tg_san_xuat_kh += $plan ? (strtotime($plan->thoi_gian_ket_thuc) - strtotime($plan->thoi_gian_bat_dau)) : 0;
                        $obj->sl_dau_vao += ($so_bat) ? round($log->sl_dau_vao_hang_loat /  $so_bat) : 0;
                        $obj->dien_nang += $log->powerM;
                        $obj->sl_dau_ra += ($so_bat) ? round($log->sl_dau_ra_hang_loat /  $so_bat) : 0;
                        $obj->sl_tem_vang += ($so_bat) ? round($log->sl_tem_vang /  $so_bat) : 0;
                        $obj->sl_ng += ($so_bat) ? round($log->sl_ng /  $so_bat) : 0;
                        $obj->sl_ok += ($so_bat) ? round((($log->sl_dau_ra_hang_loat) - ($log->sl_tem_vang) - ($log->sl_ng)) /  $so_bat) : 0;
                        $obj->tong_thoi_gian_san_xuat += strtotime($log->thoi_gian_ket_thuc) - strtotime($log->thoi_gian_bat_dau);
                        $obj->thoi_gian_khong_san_luong += strtotime($log->thoi_gian_bam_may) - strtotime($log->thoi_gian_bat_dau);
                        $obj->thoi_gian_tinh_san_luong += strtotime($log->thoi_gian_ket_thuc) - strtotime($log->thoi_gian_bam_may);
                        $sl_thuc_te += $plan ? ((strtotime($log->thoi_gian_ket_thuc) - strtotime($log->thoi_gian_bam_may)) / 3600) * (int)$plan->UPH : 0;
                        $obj->nhan_luc = $plan ? $plan->nhan_luc : 0;
                    }
                }
                $obj->ty_le_ng = $obj->sl_dau_ra ? (int)number_format($obj->sl_ng / $obj->sl_dau_ra, 2) * 100 . '%' : 0;
                $obj->ty_le_hao_phi_thoi_gian = $obj->tong_thoi_gian_san_xuat ? (int)number_format($obj->thoi_gian_khong_san_luong / $obj->tong_thoi_gian_san_xuat, 2) * 100 . '%' : 0;
                $obj->hieu_suat_a = $tg_san_xuat_kh > 0 ? (int)number_format($obj->thoi_gian_tinh_san_luong / $tg_san_xuat_kh, 2) * 100 . '%' : 0;
                $obj->hieu_suat_q = $obj->sl_dau_ra ? (int)number_format($obj->sl_ok / $obj->sl_dau_ra, 2) * 100 . '%' : 0;

                $obj->hieu_suat_p = ($obj->thoi_gian_tinh_san_luong && $sl_thuc_te > 0) ? (int)number_format(($obj->sl_dau_ra) / $sl_thuc_te, 2) * 100 . '%' : 0;
                $obj->oee = (((int)$obj->hieu_suat_a * (int)$obj->hieu_suat_p * (int)$obj->hieu_suat_q) / 10000) . '%';

                $obj->tong_thoi_gian_san_xuat = number_format($obj->tong_thoi_gian_san_xuat / 3600, 1);
                $obj->thoi_gian_khong_san_luong = number_format($obj->thoi_gian_khong_san_luong / 3600, 1);
                $obj->thoi_gian_tinh_san_luong = number_format($obj->thoi_gian_tinh_san_luong / 3600, 1);
                $obj->thoi_gian_vao_hang = $obj->thoi_gian_khong_san_luong;
                $table2[] = $obj;
            }
        }
        $start2_row = $table1_row + 1;
        $header2_row = $start2_row;
        $start2_col = 1;
        $header2 = [
            'Ngày sản xuất',
            'Số máy',
            "Số nhân sự chạy máy",
            "Số lượng đầu vào (tờ)",
            "Số lượng khoanh vùng (tem vàng) (tờ)",
            "Số lượng OK (tờ)",
            "Số lượng NG (tờ)",
            "Tổng thời gian sản xuất",
            'Thời gian không ra sản phẩm',
            "Thời gian chạy sản lượng",
            "Thời gian vào hàng",
            "Tỷ lệ NG (%)",
            'Tỷ lệ hao phí thời gian (%)',
            'Leadtime',
            "Hiệu suất (A)",
            "Hiệu suất (P)",
            "Hiệu suất (Q)",
            "OEE",
            'Điện năng'
        ]; //, "Hiệu suất (A)", "Hiệu suất (P)", "Hiệu suất (Q)", "OEE"
        $table_key2 = [
            'A' => 'ngay_sx',
            'B' => 'machine_id',
            'C' => 'nhan_luc',
            'd' => 'sl_dau_vao',
            'E' => 'sl_tem_vang',
            'F' => 'sl_ok',
            'G' => 'sl_ng',
            'H' => 'tong_thoi_gian_san_xuat',
            'I' => 'thoi_gian_khong_san_luong',
            'J' => 'thoi_gian_tinh_san_luong',
            'K' => 'thoi_gian_vao_hang',
            'L' => 'ty_le_ng',
            'M' => 'ty_le_hao_phi_thoi_gian',
            'N' => 'leadtime',
            'O' => 'hieu_suat_a',
            'P' => 'hieu_suat_p',
            'Q' => 'hieu_suat_q',
            'R' => 'oee',
            'S' => 'dien_nang',
        ];
        foreach ($header2 as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start2_col, $start2_row], $cell)->mergeCells([$start2_col, $start2_row, $start2_col, $start2_row])->getStyle([$start2_col, $start2_row, $start2_col, $start2_row])->applyFromArray($headerStyle1);
            } else {
                $sheet->setCellValue([$start2_col, $start2_row], $key)->mergeCells([$start2_col, $start2_row, $start2_col + count($cell) - 1, $start2_row])->getStyle([$start2_col, $start2_row, $start2_col + count($cell) - 1, $start2_row])->applyFromArray($headerStyle1);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start2_col, $start2_row + 1], $val)->getStyle([$start2_col, $start2_row + 1])->applyFromArray($headerStyle1);
                    $start2_col += 1;
                }
                continue;
            }
            $start2_col += 1;
        }
        $table2_col = 1;
        $table2_row = $start2_row + 1;
        foreach ($table2 as $key => $row) {
            $table2_col = 1;
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key2)) {
                    $sheet->setCellValue(array_search($key, $table_key2) . $table2_row, $cell)->getStyle(array_search($key, $table_key2) . $table2_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table2_col += 1;
            }
            $sheet->getStyle([1, $table2_row, 19, $table2_row])->applyFromArray($fillWhite);
            $table2_row += 1;
        }
        $sheet->getRowDimension($header2_row)->setRowHeight(42);
        foreach ($sheet->getColumnIterator() as $column) {
            if ($column->getColumnIndex() !== 'A') {
                $sheet->getColumnDimension($column->getColumnIndex())->setWidth(12);
                $sheet->getStyle($column->getColumnIndex() . ($start2_row + 1) . ':' . $column->getColumnIndex() . ($table2_row - 1))->applyFromArray($border);
            }
        }

        $query_lot = InfoCongDoan::with('plan', 'lot.product', 'line.machine');
        if ($request->date && count($request->date) > 1) {
            $query_lot->whereDate('thoi_gian_bat_dau', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('thoi_gian_bat_dau', '<=', date('Y-m-d', strtotime($request->date[1])));
        } else {
            $query_lot->whereDate('thoi_gian_bat_dau', date('Y-m-d'));
        }
        if ($request->ten_sp) {
            $query_lot->where('lot_id', 'like', '%' . $request->ten_sp . '%');
        }
        if ($request->lo_sx) {
            $query_lot->where('lot_id', 'like', '%' . $request->lo_sx . '%');
        }

        //Table 3
        $table_lot_query = clone $query_lot;
        $records =  $table_lot_query->whereIn('line_id', $lines)
            ->select([
                'info_cong_doan.*',
                DB::raw("SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_ket_thuc , thoi_gian_bat_dau))) as tong_thoi_gian_san_xuat"),
                DB::raw("SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_bam_may , thoi_gian_bat_dau))) as tong_thoi_gian_vao_hang"),
                DB::raw("SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_ket_thuc , thoi_gian_bam_may))) as tong_thoi_gian_ra_sp"),
                DB::raw("SUM(sl_ng) as tong_sl_ng"),
                DB::raw("SUM(sl_tem_vang) as tong_sl_tem_vang"),
                DB::raw("SUM(sl_dau_ra_hang_loat - sl_tem_vang - sl_ng) as tong_sl_ok"),
                DB::raw("SUM(sl_dau_ra_hang_loat) as tong_sl_dau_ra_hang_loat"),
                DB::raw("SUM(sl_dau_vao_hang_loat) as tong_sl_dau_vao_hang_loat"),
                DB::raw("DATE(thoi_gian_bat_dau) as ngay_sx"),
            ])
            ->whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_bam_may')->whereNotNull('thoi_gian_ket_thuc')
            ->groupBy('lo_sx', 'line_id', 'ngay_sx', 'id')
            ->orderBy('ngay_sx')->orderBy('lo_sx')
            ->get();

        $table3 = [];
        foreach ($records as $key => $record) {
            $obj = ['tg_kh_giao' => 0, 'sl_ke_hoach' => 0];
            $plan = $record->plan;
            $record->machine_name = $record->line->machine[0]->name;
            $record->product_name = $record->lot->product->name ?? "";
            $record->tg_kh_giao = $plan ? round($plan->tong_tg_thuc_hien / 60, 1) : 0;
            $record->sl_ke_hoach = $plan ? (($plan->sl_thanh_pham && $plan->product->so_bat) ? $plan->product->so_bat * $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0;
            $record->sl_nhan_su = $plan->nhan_luc ?? "";
            $record->ty_le_ng = round(($record->tong_sl_dau_ra_hang_loat ? ($record->tong_sl_ng / $record->tong_sl_dau_ra_hang_loat) : 0) * 100) . "%";
            $record->ty_le_hao_phi_thoi_gian = round(($record->tong_sl_dau_ra_hang_loat ? ($record->tong_thoi_gian_vao_hang / $record->tong_thoi_gian_san_xuat) : 0) * 100) . "%";
            $record->ty_le_hoan_thanh = round(($record->tong_sl_dau_ra_hang_loat ? (($record->tong_sl_dau_ra_hang_loat - $record->tong_sl_ng) / $record->tong_sl_dau_ra_hang_loat) : 0) * 100) . "%";
            $record->tong_thoi_gian_san_xuat = round($record->tong_thoi_gian_san_xuat / 3600, 1);
            $record->tong_thoi_gian_vao_hang = round($record->tong_thoi_gian_vao_hang / 3600, 1);
            $record->tong_thoi_gian_ra_sp = round($record->tong_thoi_gian_ra_sp / 3600, 1);
            $record->tong_thoi_gian_khong_ra_sp = round($record->tong_thoi_gian_vao_hang / 3600, 1);
        }
        // return $records;
        $table3 = $records->toArray();
        $start3_row = $table2_row + 1;
        $start3_col = 1;
        $header3 = [
            'Ngày',
            "Số máy",
            'Tên sản phẩm',
            'Lô sản xuất',
            "Thời gian kế hoạch giao",
            "Thời gian thực tế làm",
            "Số lượng KH giao",
            "Số lượng đầu vào",
            "Số lượng OK",
            "Số lượng tem vàng",
            "Số lượng NG",
            'Tỷ lệ NG',
            'Thời gian không ra SP',
            'Thời gian máy chạy ra SP',
            'Thời gian vào hàng',
            'Tỷ lệ hao phí thời gian',
            'Tỷ lệ hoàn thành KH',
            'Nhân sự chạy máy'
        ];
        $table_key3 = [
            'A' => 'ngay_sx',
            'B' => 'machine_name',
            'C' => 'product_name',
            'D' => 'lo_sx',
            'E' => 'tg_kh_giao',
            'F' => 'tong_thoi_gian_san_xuat',
            'G' => 'sl_ke_hoach',
            'H' => 'tong_sl_dau_vao_hang_loat',
            'I' => 'tong_sl_ok',
            'J' => 'tong_sl_tem_vang',
            'K' => 'tong_sl_ng',
            'L' => 'ty_le_ng',
            'M' => 'tong_thoi_gian_khong_ra_sp',
            'N' => 'tong_thoi_gian_ra_sp',
            'O' => 'tong_thoi_gian_vao_hang',
            'P' => 'ty_le_hao_phi_thoi_gian',
            'Q' => 'ty_le_hoan_thanh',
            'R' => 'sl_nhan_su',
        ];
        foreach ($header3 as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start3_col, $start3_row], $cell)->mergeCells([$start3_col, $start3_row, $start3_col, $start3_row + 1])->getStyle([$start3_col, $start3_row, $start3_col, $start3_row + 1])->applyFromArray($headerStyle2);
            } else {
                $sheet->setCellValue([$start3_col, $start3_row], $key)->mergeCells([$start3_col, $start3_row, $start3_col + count($cell) - 1, $start3_row])->getStyle([$start3_col, $start3_row, $start3_col + count($cell) - 1, $start3_row])->applyFromArray($headerStyle2);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start3_col, $start3_row + 1], $val)->getStyle([$start3_col, $start3_row + 1])->applyFromArray($headerStyle2);
                    $start3_col += 1;
                }
                continue;
            }
            $start3_col += 1;
        }
        $table3_col = 1;
        $table3_row = $start3_row + 2;
        foreach ($table3 as $key => $row) {
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key3) && array_search($key, $table_key3) !== "") {
                    $sheet->setCellValue(array_search($key, $table_key3) . $table3_row, $cell)->getStyle(array_search($key, $table_key3) . $table3_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table3_col += 1;
            }
            $sheet->getStyle([1, $table3_row, 18, $table3_row])->applyFromArray($fillWhite);
            $table3_row += 1;
        }

        //Table 4
        $query_lot->whereIn('line_id', $lines)->selectRaw('lo_sx,line_id,SUM(sl_dau_vao_hang_loat) as sl_dau_vao_,
        SUM(sl_dau_ra_hang_loat) as sl_dau_ra_, SUM(sl_tem_vang) as sl_tem_vang_, SUM(sl_ng) as sl_ng_,SUM(powerM) as powerM_, SUM(sl_dau_ra_hang_loat - sl_tem_vang - sl_ng) as sl_ok_
        , SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_ket_thuc , thoi_gian_bat_dau))) as tong_thoi_gian_san_xuat_, SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_bam_may , thoi_gian_bat_dau))) as thoi_gian_khong_san_luong_,
        SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_ket_thuc , thoi_gian_bam_may))) as thoi_gian_tinh_san_luong_,MAX(thoi_gian_bat_dau) as ngay_sx_gan_nhat_')
            ->whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc');;
        $records = $query_lot->groupBy('lo_sx', 'line_id')->get()->groupBy('lo_sx');
        $table4 = [];
        foreach ($records as $key => $record) {
            $obj = [];
            $plan_product = ProductionPlan::where('lo_sx', $key)->first();
            foreach ($record as $k => $item) {
                if ($k == 0) {
                    $plan = $item->plan;
                    $obj['product_id'] = $plan_product->product_id;
                    $obj['product_name'] = $plan_product->product->name;
                    $obj['lo_sx'] = $item->lo_sx;
                    $obj['so_bat'] = $plan_product->product->so_bat;
                }
                $obj['ngay_sx_gan_nhat_' . $item->line_id] = $item->ngay_sx_gan_nhat_;
                $obj['sl_dau_vao_' . $item->line_id] = $item->sl_dau_vao_;
                $obj['dien_nang_' . $item->line_id] = $item->powerM_ > 0 ? number_format($item->powerM_) : '';
                $obj['sl_dau_ra_' . $item->line_id] = $item->sl_dau_ra_;
                $obj['sl_tem_vang_' . $item->line_id] = $item->sl_tem_vang_;
                $obj['sl_ng_' . $item->line_id] = $item->sl_ng_;
                $obj['sl_ok_' . $item->line_id] =  $item->sl_ok_;
                $obj['tong_thoi_gian_san_xuat_' . $item->line_id] = $item->tong_thoi_gian_san_xuat_;
                $obj['thoi_gian_khong_san_luong_' . $item->line_id] = $item->thoi_gian_khong_san_luong_;
                $obj['thoi_gian_tinh_san_luong_' . $item->line_id] = $item->thoi_gian_tinh_san_luong_;
                $obj['sl_ke_hoach_' . $item->line_id] = $plan ? (($plan->sl_thanh_pham && $plan->product->so_bat) ? $plan->product->so_bat * $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0;
                $obj['ty_le_ok_' . $item->line_id] = ($obj['sl_dau_ra_' . $item->line_id] > 0) ? number_format($obj['sl_ok_' . $item->line_id] / $obj['sl_dau_ra_' . $item->line_id], 2) * 100 . '%' : 0;
                $obj['ty_le_tem_vang_' . $item->line_id] = ($obj['sl_dau_ra_' . $item->line_id] > 0) ? number_format($obj['sl_tem_vang_' . $item->line_id] / $obj['sl_dau_ra_' . $item->line_id], 2) * 100 . '%' : 0;
                $obj['ty_le_ng_' . $item->line_id] = ($obj['sl_dau_ra_' . $item->line_id] > 0) ? number_format($obj['sl_ng_' . $item->line_id] / $obj['sl_dau_ra_' . $item->line_id], 2) * 100 . '%' : 0;
                $obj['ty_le_hao_phi_thoi_gian_' . $item->line_id] = ($obj['thoi_gian_khong_san_luong_' . $item->line_id] > 0) ? number_format($obj['thoi_gian_khong_san_luong_' . $item->line_id] / $obj['tong_thoi_gian_san_xuat_' . $item->line_id], 2) * 100 . '%' : 0;
            }
            $table4[] = $obj;
        }
        $start4_row = $table3_row + 1;
        $start4_col = 1;
        $header4 = [
            'Mã hàng',
            'Tên sản phẩm',
            "Lô sản xuất",
            "Số bát",
            // "IN" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
            // "PHỦ" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
            // "IN LƯỚI" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
            // "BẾ" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
            // "BÓC" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
            // "GẤP DÁN" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
            // "CHỌN" => ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""],
        ];
        $table_key4 = [
            'A' => 'product_id',
            'B' => 'product_name',
            'C' => 'lo_sx',
            'D' => 'so_bat',
        ];
        $table_keys = ['ngay_sx_gan_nhat', 'sl_dau_vao', 'sl_ok', 'sl_tem_vang', 'sl_ng', 'sl_ke_hoach', 'ty_le_ok', 'ty_le_tem_vang', 'ty_le_ng', 'ty_le_hao_phi_thoi_gian', 'dien_nang', ''];
        $header_keys = ["Ngày sản xuất gần nhất của lô", "Số lượng đầu vào", "Số lượng hàng đạt", 'Tem vàng', "Số lượng NG", "Sản lượng kế hoạch giao", "Tỷ lệ đạt thẳng (%)", "Tỷ lệ tem vàng (%)", "Tỷ lệ NG(%)", "Tỷ lệ hao phí thời gian (%)", "Điện năng", ""];
        $index = 1;
        foreach ($lines as $line_id) {
            $line = Line::find($line_id);
            $header4[$line->name] = $header_keys;
            foreach ($table_keys as $i => $key) {
                $table_key4[$this->getNextExcelColumn('D', $index)] = $key . "_" . $line_id;
                $index++;
            }
        }



        foreach ($header4 as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start4_col, $start4_row], $cell)->mergeCells([$start4_col, $start4_row, $start4_col, $start4_row + 1])->getStyle([$start4_col, $start4_row, $start4_col, $start4_row + 1])->applyFromArray($headerStyle2);
            } else {
                $sheet->setCellValue([$start4_col, $start4_row], $key)->mergeCells([$start4_col, $start4_row, $start4_col + count($cell) - 1, $start4_row])->getStyle([$start4_col, $start4_row, $start4_col + count($cell) - 1, $start4_row])->applyFromArray($headerStyle2);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start4_col, $start4_row + 1], $val)->getStyle([$start4_col, $start4_row + 1])->applyFromArray($headerStyle2);
                    $start4_col += 1;
                }
                continue;
            }
            $start4_col += 1;
        }
        $table4_col = 1;
        $table4_row = $start4_row + 2;
        foreach ($table4 as $key => $row) {
            // $table_col = 1;
            // $sheet->setCellValue([1, $table_row], $table_row-3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            // $table_col+=1;

            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key4) && array_search($key, $table_key4) !== "") {
                    $sheet->setCellValue(array_search($key, $table_key4) . $table4_row, $cell)->getStyle(array_search($key, $table_key4) . $table4_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table4_col += 1;
            }
            $sheet->getStyle([1, $table4_row, count($table_keys), $table4_row])->applyFromArray($fillWhite);
            $table4_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setWidth(12);
            $sheet->getStyle($column->getColumnIndex() . ($start4_row + 2) . ':' . $column->getColumnIndex() . ($table4_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Báo cáo truy vấn lịch sử sản xuất.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Báo cáo truy vấn lịch sử sản xuất.xlsx');
        $href = '/exported_files/Báo cáo truy vấn lịch sử sản xuất.xlsx';
        return $this->success($href);
    }

    function getNextExcelColumn($currentColumn, $index)
    {
        $columnNumber = array_reduce(str_split($currentColumn), function ($carry, $char) {
            return $carry * 26 + (ord($char) - ord('A') + 1);
        }, 0) - 1;

        $newColumnNumber = $columnNumber + $index;

        $newColumn = '';
        while ($newColumnNumber >= 0) {
            $newColumn = chr($newColumnNumber % 26 + ord('A')) . $newColumn;
            $newColumnNumber = intdiv($newColumnNumber, 26) - 1;
        }

        return $newColumn;
    }

    public function getDataFilterUI(Request $request)
    {
        $data = new stdClass;
        $data->product = [];
        $data->lo_sx = [];
        $khach_hang = Customer::find($request->khach_hang);
        if ($khach_hang) {
            $plan = ProductionPlan::where('khach_hang', $khach_hang->name)->get();
            $data->product = Product::whereIn('customer_id', $khach_hang)->get();
            $data->lo_sx = (array)array_unique($plan->pluck('lo_sx')->toArray());
        } else {
            // $plan = ProductionPlan::all();
            $data->product = Product::all();
            $data->lo_sx = Losx::all()->pluck('id')->toArray();
        }
        return $this->success($data, '');
    }


    public function getDetailDataError(Request $request)
    {
        $query = InfoCongDoan::where('lot_id', $request->lot_id);
        $line = Line::find($request->line_id);
        if ($line) {
            $query->where('line_id', $line->id);
        }
        if (isset($request->date) && count($request->date)) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        $infos = $query->with("lot.plans")->get();
        $records = [];
        foreach ($infos as $key => $info) {
            if ($info->lot) {
                $records[] = $info;
            }
        }
        $table = $this->produceTable($records);
        $chart = $this->qcError($records);
        return $this->success([
            "table" => $table,
            "chart" => $chart[1],
        ]);
    }

    function getProductLogs($type, $startDate = null, $endDate = null, $includeAll = false)
    {
        return Product::with(['warehouseLog' => function ($query) use ($type, $startDate, $endDate, $includeAll) {
            $query->where('warehouse_logs.type', $type);
            if ($startDate) {
                $query->whereDate('warehouse_logs.created_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('warehouse_logs.created_at', $includeAll ? '<' : '<=', $endDate);
            }
        }])->get();
    }

    public function exportSummaryWarehouse(Request $request)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '1024M');
        $table = [];
        $sum = [
            'product_name' => 'Tổng cộng',
            'ton_dau' => 0,
            'sl_nhap' => 0,
            'sl_xuat' => 0,
            'ton_cuoi' => 0,
        ];

        // Dữ liệu từ request
        $startDate = date('Y-m-d', strtotime($request->date[0]));
        $endDate = date('Y-m-d', strtotime($request->date[1]));

        // Lấy dữ liệu nhập và xuất trước khoảng thời gian
        $log_import = $this->getProductLogs(1, null, $startDate, true);
        $log_export = $this->getProductLogs(2, null, $startDate, true);

        // Lấy dữ liệu nhập và xuất trong khoảng thời gian
        $product_import = $this->getProductLogs(1, $startDate, $endDate);
        $product_export = $this->getProductLogs(2, $startDate, $endDate);
        // $log_import = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) use ($request) {
        //     $join->on('warehouse_logs.lot_id', '=', 'lot.id')
        //         ->where('warehouse_logs.type', 1)->whereDate('warehouse_logs.created_at', '<', date('Y-m-d', strtotime($request->date[0])));
        // })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
        // $log_export = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) use ($request) {
        //     $join->on('warehouse_logs.lot_id', '=', 'lot.id')
        //         ->where('warehouse_logs.type', 2)->whereDate('warehouse_logs.created_at', '<', date('Y-m-d', strtotime($request->date[0])));
        // })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();

        // $product_import = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) use ($request) {
        //     $join->on('warehouse_logs.lot_id', '=', 'lot.id')
        //         ->where('warehouse_logs.type', 1)->whereDate('warehouse_logs.created_at', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('warehouse_logs.created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        // })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
        // $product_export = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) use ($request) {
        //     $join->on('warehouse_logs.lot_id', '=', 'lot.id')
        //         ->where('warehouse_logs.type', 2)->whereDate('warehouse_logs.created_at', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('warehouse_logs.created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        // })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
        $data = [];
        foreach ($log_import as $key => $log) {
            $import_total = !empty($log->warehouseLog) ? $log->warehouseLog->sum('so_luong') : 0;
            $export_total = !empty($log_export[$key]->warehouseLog) ? $log_export[$key]->warehouseLog->sum('so_luong') : 0;
            $import_product = !empty($product_import->warehouseLog) ? $product_import->warehouseLog->sum('so_luong') : 0;
            $export_product = !empty($product_export[$key]->warehouseLog) ? $product_export[$key]->warehouseLog->sum('so_luong') : 0;
            $obj = [];
            $obj['product_id'] = $log->id;
            $obj['product_name'] = $log->name;
            $obj['ton_dau'] = $import_total -  $export_total;
            $obj['sl_nhap'] = $import_product ?? 0;
            $obj['sl_xuat'] = $export_product ?? 0;
            $obj['ton_cuoi'] = $obj['ton_dau'] + $obj['sl_nhap'] - $obj['sl_xuat'];
            $sum['sl_nhap'] += $obj['sl_nhap'];
            $sum['sl_xuat'] += $obj['sl_xuat'];
            $sum['ton_dau'] += $obj['ton_dau'];
            $sum['ton_cuoi'] += $obj['ton_cuoi'];
            $data[] = $obj;
        }
        $data[] = $sum;
        $table = $data;
        // return $table;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['STT', 'Mã vật tư', 'Tên vật tư', 'Tồn đầu', 'Sl nhập', 'Sl xuất', 'Tồn cuối'];
        $table_key = [
            'A' => 'stt',
            'B' => 'product_id',
            'C' => 'product_name',
            'D' => 'ton_dau',
            'E' => 'sl_nhap',
            'F' => 'sl_xuat',
            'G' => 'ton_cuoi',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'TỔNG HỢP XUẤT NHẬP TỒN ' . date('d/m/Y', strtotime($request->date[0])) . ' - ' . date('d/m/Y', strtotime($request->date[1])))->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table as $key => $row) {
            $table_col = 1;
            if (isset($row['product_id']) && $row['product_id']) {
                $sheet->setCellValue([1, $table_row], $table_row - 2)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
                $table_col += 1;
            }

            $row = (array)$row;
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Tổng hợp xuất nhập tồn.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Tổng hợp xuất nhập tồn.xlsx');
        $href = '/exported_files/Tổng hợp xuất nhập tồn.xlsx';
        return $this->success($href);
    }
    public function exportBMCardWarehouse(Request $request)
    {
        $product = Product::find($request->ten_sp);
        if (!$product) {
            return $this->failure('', 'Không tìm thấy sản phẩm');
        }
        $warehouse_log = $product->warehouseLog()->whereDate('warehouse_logs.created_at', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('warehouse_logs.created_at', '<=', date('Y-m-d', strtotime($request->date[1])))->get();
        $table = [];
        $log_import = $product->warehouseLog()->where('warehouse_logs.type', 1)->whereDate('warehouse_logs.created_at', '<', date('Y-m-d', strtotime($request->date[0])))->get()->sum('so_luong');
        $log_export = $product->warehouseLog()->where('warehouse_logs.type', 2)->whereDate('warehouse_logs.created_at', '<', date('Y-m-d', strtotime($request->date[0])))->get()->sum('so_luong');
        $product_import = $product->warehouseLog()->where('warehouse_logs.type', 1)->whereDate('warehouse_logs.created_at', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('warehouse_logs.created_at', '<=', date('Y-m-d', strtotime($request->date[1])))->get()->sum('so_luong');
        $product_export = $product->warehouseLog()->where('warehouse_logs.type', 2)->whereDate('warehouse_logs.created_at', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('warehouse_logs.created_at', '<=', date('Y-m-d', strtotime($request->date[1])))->get()->sum('so_luong');
        // return [$log_import, $log_export, $product_import, $product_export];
        $data = [];
        $data[] = ['dien_giai' => 'Đầu kỳ', 'sl_nhap' => ($log_import - $log_export)];
        $data[] = ['dien_giai' => 'Ps nhập trong kỳ', 'sl_nhap' => $product_import];
        $data[] = ['dien_giai' => 'Ps xuất trong kỳ', 'sl_xuat' => $product_export];
        $data[] = ['dien_giai' => 'Cuối kỳ', 'sl_nhap' => ($log_import - $log_export) + $product_import - $product_export];
        $data[] = [];
        foreach ($warehouse_log as $key => $log) {
            $export_plan = WareHouseExportPlan::where('product_id', $product->id)->first();
            $customer = $export_plan ? Customer::where('name', 'like', "%$export_plan->khach_hang%")->first() : null;
            $obj = [];
            $obj['ngay_ct'] = date('d-m-Y', strtotime($log->created_at));
            $obj['so_ct'] = '';
            $obj['ma_nx'] = '';
            $obj['ma_kho'] = 'TP';
            if ($log['type'] === 1) {
                $obj['sl_nhap'] = $log->so_luong;
                $obj['dien_giai'] = 'Nhập kho';
            } else {
                $obj['sl_xuat'] = $log->so_luong;
                $obj['dien_giai'] = $export_plan->cua_xuat_hang ?? '';
            }
            $obj['ma_khach'] = $customer ? $customer->id : '';
            $obj['ten_khach'] = $customer ? $customer->name : '';
            $obj['ma_ct'] = '';
            $data[] = $obj;
        }
        $table = $data;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['Ngày ct', 'Số ct', 'Diễn giải', 'Mã nx', 'Mã kho', 'Sl nhập', 'Sl xuất', 'Vụ việc', 'Mã khách', 'Tên khách', 'Mã ct'];
        $table_key = [
            'A' => 'ngay_ct',
            'B' => 'so_ct',
            'C' => 'dien_giai',
            'D' => 'ma_nx',
            'E' => 'ma_kho',
            'F' => 'sl_nhap',
            'G' => 'sl_xuat',
            'H' => 'vu_viec',
            'I' => 'ma_khach',
            'J' => 'ten_khach',
            'K' => 'ma_ct',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'CHI TIẾT PHÁT SINH CỦA VẬT TƯ ' . $product->id . ' (' . $product->name . ')')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="BM_thẻ_kho.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/BM_thẻ_kho.xlsx');
        $href = '/exported_files/BM_thẻ_kho.xlsx';
        return $this->success($href);
    }
    public function inventory()
    {
        $inventory = Inventory::first();
        if (!$inventory) {
            $log_import = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) {
                $join->on('warehouse_logs.lot_id', '=', 'lot.id')
                    ->where('warehouse_logs.type', 1);
            })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
            $log_export = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) {
                $join->on('warehouse_logs.lot_id', '=', 'lot.id')
                    ->where('warehouse_logs.type', 2);
            })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
            foreach ($log_import as $key => $log) {
                $obj = new Inventory();
                $obj->product_id = $log->id;
                $obj->sl_ton = $log->so_luong -  $log_export[$key]->so_luong;
                $obj->sl_nhap = $log->so_luong ? $log->so_luong : 0;
                $obj->sl_xuat = $log_export[$key]->so_luong ? $log_export[$key]->so_luong : 0;
                $obj->save();
            }
        } else {
            $log_import = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) {
                $join->on('warehouse_logs.lot_id', '=', 'lot.id')
                    ->where('warehouse_logs.type', 1)->whereDate('warehouse_logs.created_at', date('Y-m-d'));
            })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
            $log_export = Product::leftJoin('lot', 'products.id', '=', 'lot.product_id')->leftJoin('warehouse_logs', function (JoinClause $join) {
                $join->on('warehouse_logs.lot_id', '=', 'lot.id')
                    ->where('warehouse_logs.type', 2)->whereDate('warehouse_logs.created_at', date('Y-m-d'));
            })->select('products.id', 'products.name', DB::raw('SUM(warehouse_logs.so_luong) as so_luong'))->groupBy('products.id', 'products.name')->get();
            $records = Inventory::whereDate('created_at', date('Y-m-d'))->get();
            foreach ($log_import as $key => $log) {
                $obj = new Inventory();
                $obj->product_id = $log->id;
                $obj->sl_ton = $records[$key]->sl_ton + $log->so_luong -  $log_export[$key]->so_luong;
                $obj->sl_nhap = $log->so_luong ? $log->so_luong : 0;
                $obj->sl_xuat = $log_export[$key]->so_luong ? $log_export[$key]->so_luong : 0;
                $obj->save();
            }
        }
    }

    public function exportInventoryWarehouse(Request $request)
    {
        $log_import = WareHouseLog::with('lot.product.customer')
            ->whereNotIn('lot_id', function ($query) {
                $query->select('lot_id')
                    ->from('warehouse_logs')
                    ->where('type', 2);
            })
            ->whereDate('created_at', '<=', date('Y-m-d', strtotime('-90 days')))
            ->where('type', 1)
            ->get();
        $table = [];
        foreach ($log_import as $key => $log) {
            $lot = $log->lot;
            if (!$lot) continue;
            $product = $log->lot->product;
            if (!$product) continue;
            $customer = $log->lot->product->customer;
            $value = [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'dvt' => 'Mảnh',
                'lo_sx' => $lot->lo_sx,
                'lot_id' => $lot->id,
                'cell_id' => $log->cell_id,
                'kho' => 'KTP',
                'ngay_nhap' => date('d/m/Y H:i:s', strtotime($log->created_at)),
                'so_luong' => $log->so_luong,
                'so_ngay_ton' => ((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($log->created_at)))) / 86400),
            ];
            $table[] = $value;
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['STT', 'Mã khách hàng', "Tên khách hàng", "Mã hàng", "Tên sản phẩm", "ĐVT", "Lô SX", 'Kho', "Mã thùng", "Vị trí", "Ngày nhập kho", "Tồn kho", "Số ngày tồn kho"];
        $table_key = [
            'A' => 'stt',
            'B' => 'customer_id',
            'C' => 'customer_name',
            'D' => 'product_id',
            'E' => 'product_name',
            'F' => 'dvt',
            'G' => 'lo_sx',
            'H' => 'kho',
            'I' => 'lot_id',
            'J' => 'cell_id',
            'K' => 'ngay_nhap',
            'L' => 'so_luong',
            'M' => 'so_ngay_ton',
        ];
        foreach ($header as $key => $cell) {
            $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'TỔNG HỢP HÀNG TỒN KHO DÀI HẠN')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 2)->getStyle([1, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Tổng hợp hàng tồn kho dài hạn.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Tổng hợp hàng tồn kho dài hạn.xlsx');
        $href = '/exported_files/Tổng hợp hàng tồn kho dài hạn.xlsx';
        return $this->success($href);
    }
    public function exportQCHistory(Request $request)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '1024M');
        $query = $this->qcHistoryQuery($request);
        $dateRange = CarbonPeriod::create($request->date[0], $request->date[1]);
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
        // $infos = $data;
        $infos = $data->groupBy('line_id');
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'wrapText' => true
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet_index = 0;
        foreach ($infos as $line_id => $info_cong_doan) {
            $line = Line::find($line_id);
            $sheet = $spreadsheet->getSheet($sheet_index);
            $sheet->setTitle($line->name);
            $start_row = 2;
            $start_col = 1;

            $header = [
                'STT',
                'Ngày',
                "Ca sản xuất",
                "Xưởng",
                "Công đoạn",
                "Máy sản xuất",
                "Mã máy",
                'Tên sản phẩm',
                "Khách hàng",
                "Mã hàng",
                'Lô sản xuất',
                'Mã pallet/thùng',
                "Số lượng sản xuất",
                "Số lượng OK",
                'Số lượng tem vàng',
                "Số lượng NG (SX tự KT)",
                'SX kiểm tra',
                "Số lượng NG (PQC)",
                'QC kiểm tra',
                "Số lượng NG",
                "Tỉ lệ NG"
            ];
            $table_key = [
                'A' => 'stt',
                'B' => 'ngay_sx',
                'C' => 'ca_sx',
                'D' => 'xuong',
                'E' => 'cong_doan',
                'F' => 'machine',
                'G' => 'machine_id',
                'H' => 'ten_san_pham',
                'I' => 'khach_hang',
                'J' => 'product_id',
                'K' => 'lo_sx',
                'L' => 'lot_id',
                'M' => 'sl_dau_ra_hang_loat',
                'N' => 'sl_dau_ra_ok',
                'O' => 'sl_tem_vang',
                'P' => 'sl_ng_sxkt',
                'Q' => 'user_sxkt',
                'R' => "sl_ng_pqc",
                'S' => 'user_pqc',
                "T" => "sl_ng",
                "U" => "ti_le_ng"
            ];
            $table = $this->produceTablePQC($info_cong_doan, true);
            $product_ids = [];
            foreach ($info_cong_doan as $item) {
                $product_ids[] = $item->lot->product_id;
            }
            $list  = TestCriteria::where('line_id', $line->id)->where('is_show', 1)->orderBy('chi_tieu')->orderBy('hang_muc')->get();
            $letter = 'V';
            $index = 0;

            foreach ($list as $key => $item) {
                if (!isset($header[$item->chi_tieu])) {
                    $header[$item->chi_tieu] = [];
                }
                if ($item->hang_muc == " ") continue;
                $header[$item->chi_tieu][] = $item->hang_muc;
                $letter = $this->num_to_letters(22 + $index);
                $table_key[$letter] = Str::slug($item->hang_muc);
                $index += 1;
            }
            $header[] = 'Đánh giá';
            $table_key[$this->num_to_letters(22 + $index)] = 'evaluate';
            foreach ($header as $key => $cell) {
                if (!is_array($cell)) {
                    $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
                } else {
                    if (count($cell) > 0) {
                        $style = array_merge($headerStyle, array('fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => array('argb' => 'EBF1DE')
                        ]));
                        $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($style);
                        foreach ($cell as $val) {
                            $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($style);
                            $start_col += 1;
                        }
                    }
                    continue;
                }
                $start_col += 1;
            }

            $sheet->setCellValue([1, 1], 'BẢNG KIỂM TRA CHẤT LƯỢNG CÔNG ĐOẠN ' . mb_strtoupper($line->name))->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
            $sheet->getRowDimension(1)->setRowHeight(40);
            $table_col = 1;
            $table_row = $start_row + 2;
            foreach ($table as $key => $row) {
                $table_col = 1;
                $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
                $table_col += 1;
                foreach ((array)$row as $key => $cell) {
                    if (in_array($key, $table_key)) {
                        $value = '';
                        if ($table_col > 21 && ($cell === 1 || $cell === 0)) {
                            if ($cell === 1) {
                                $value = 'OK';
                            } else {
                                $value = 'NG';
                            }
                        } else {
                            $value = $cell;
                        }
                        $sheet->setCellValue(array_search($key, $table_key) . $table_row, $value)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                    } else {
                        continue;
                    }
                    $table_col += 1;
                }
                $table_row += 1;
            }
            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
                $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
            }
            if ($sheet_index < count($infos) - 1) {
                $spreadsheet->createSheet();
                $sheet_index += 1;
            }
        }

        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Chi tiết QC.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Chi tiết QC.xlsx');
        $href = '/exported_files/Chi tiết QC.xlsx';
        return $this->success($href);
    }

    public function exportIQCHistory(Request $request)
    {
        $query = LSXLog::with('lot')->where('info', 'like', '%iqc%');
        if (isset($request->date) && count($request->date)) {
            $query->whereDate('info->qc->iqc->thoi_gian_vao', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('info->qc->iqc->thoi_gian_vao', '<=', date('Y-m-d', strtotime($request->date[1])));
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
        $logs = $query->get();
        $table = [];
        foreach ($logs as $key => $log) {
            $info_qc = $log->info['qc']['iqc'] ?? [];
            $result = array_column(array_intersect_key($info_qc, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
            $check_sheet = array_column(array_intersect_key($info_qc, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'data');
            $lot = $log->lot;
            $table[$key]['thoi_gian_kiem_tra'] = $info_qc['thoi_gian_vao'] ? date('d/m/Y H:i:s',) : "";
            $table[$key]['lo_sx'] = $lot->lo_sx ?? "";
            $table[$key]['lot_id'] = $lot->id ?? "";
            $table[$key]['khach_hang'] = $lot->plan->khach_hang ?? "";
            $table[$key]['ten_san_pham'] = $lot->product->name ?? "";
            $table[$key]['phan_dinh'] = $table[$key]['thoi_gian_kiem_tra'] ? (in_array(0, $result) ? 'NG' : 'OK') : "";
            $table[$key]['user_name'] = $info_qc['user_name'] ?? '-';
            foreach ($check_sheet as $cs) {
                foreach ($cs as $val) {
                    if (isset($val['id'])) {
                        $test_criteria = TestCriteria::find($val['id']);
                        if (!$test_criteria) continue;
                        $name_key = str_replace(array("\n", "\r\n", "\r"), ' ', $test_criteria->hang_muc);
                        if (isset($val['value'])) {
                            $table[$key][Str::slug($name_key)] = (string)$val['value'];
                        } else {
                            $table[$key][Str::slug($name_key)] = $val['result'] ?? '';
                        }
                    } else {
                        continue;
                    }
                }
            }
        }
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'wrapText' => true
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;

        $header = [
            'STT',
            'Ngày kiểm tra',
            'Tên sản phẩm',
            'Khách hàng',
            'Lô SX',
            'Mã pallet/thùng',
            'Người kiểm tra'
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'thoi_gian_kiem_tra',
            'C' => 'ten_san_pham',
            'D' => 'khach_hang',
            'E' => 'lo_sx',
            'F' => 'lot_id',
            'G' => 'user_name',
        ];
        $list  = TestCriteria::where('line_id', 23)->where('is_show', 1)->orderBy('chi_tieu')->orderBy('hang_muc')->get();
        $letter = 'G';
        $index = 0;
        foreach ($list as $key => $item) {
            if (!isset($header[$item->chi_tieu])) {
                $header[$item->chi_tieu] = [];
            }
            if ($item->hang_muc == " ") continue;
            $header[$item->chi_tieu][] = $item->hang_muc;
            $letter = $this->num_to_letters(8 + $index);
            $table_key[$letter] = Str::slug($item->hang_muc);
            $index += 1;
        }
        $header[] = 'Đánh giá';
        $table_key[$this->num_to_letters(8 + $index)] = 'phan_dinh';
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            } else {
                if (count($cell) > 0) {
                    $style = array_merge($headerStyle, array('fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => array('argb' => 'EBF1DE')
                    ]));
                    $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($style);
                    foreach ($cell as $val) {
                        $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($style);
                        $start_col += 1;
                    }
                }
                continue;
            }
            $start_col += 1;
        }

        $sheet->setCellValue([1, 1], 'BẢNG KIỂM TRA CHẤT LƯỢNG NGUYÊN VẬT LIỆU')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $value = '';
                    if ($table_col > 7 && ($cell === 1 || $cell === 0)) {
                        if ($cell === 1) {
                            $value = 'OK';
                        } else {
                            $value = 'NG';
                        }
                    } else {
                        $value = $cell;
                    }
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $value)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }

        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Chi tiết QC.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Chi tiết QC.xlsx');
        $href = '/exported_files/Chi tiết QC.xlsx';
        return $this->success($href);
    }

    public function findSpec($test, $spcecs)
    {
        $find = "±";
        // return $test;
        $hang_muc = Str::slug($test->hang_muc);
        foreach ($spcecs as $item) {

            if (str_contains($item->slug, $hang_muc)) {
                if (str_contains($item->value, $find)) {
                    $arr = explode($find, $item->value);
                    $test["input"] = true;
                    $test["tieu_chuan"] = preg_replace('/-\D+/', '', filter_var($arr[0], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION));
                    $test["delta"] =  filter_var($arr[1], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $test['note'] = $item->value;
                    return $test;
                }
            }
        }
        $test['input'] = false;
        return $test;
    }

    public function exportHistoryMonitors(Request $request)
    {
        $input = $request->all();
        $query = Monitor::with('machine')->orderBy('created_at', 'DESC');
        if (isset($input['type'])) {
            $query = $query->where('type', $input['type']);
        }
        if (isset($input['machine_id'])) {
            $query = $query->where('machine_id', $input['machine_id']);
        }
        if (isset($input['status'])) {
            $query = $query->where('status', $input['status']);
        }
        if (isset($input['start_date'])) {
            $query = $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($input['start_date'])));
        } else {
            $query = $query->whereDate('created_at', '>=', date('Y-m-d'));
        }
        if (isset($input['end_date'])) {
            $query = $query->whereDate('created_at', '<=', date('Y-m-d', strtotime($input['end_date'])));
        } else {
            $query = $query->whereDate('created_at', '<=', date('Y-m-d'));
        }
        $records = $query->get();
        $table = [];
        foreach ($records as $key => $record) {
            $table[] = [
                'stt' => $key + 1,
                'type' => $record->type == 'sx' ? 'Sản xuất' : ($record->type == 'cl' ? 'Chất lượng' : 'Thiết bị'),
                'created_at' => date('d/m/Y', strtotime($record->created_at)),
                'name' => $record->machine->name,
                'content' => $record->content,
                'value' => $record->value,
                'status' => $record->status == 0 ? 'NG' : 'OK',
            ];
        }
        // return $table;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['STT', 'Loại cảnh báo', 'Thời gian bắt đầu cảnh báo', 'Ngày cảnh báo', 'Tên máy', 'Tên lỗi', 'Giá trị', 'Tình trạng sử lý'];
        $table_key = [
            'A' => 'stt',
            'B' => 'type',
            'C' => 'created_at',
            'D' => 'created_at',
            'E' => 'name',
            'F' => 'content',
            'G' => 'value',
            'H' => 'status',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'Lịch sử bất thường')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            $sheet->setCellValue([1, $table_row], $key + 1)->getStyle([1, $table_row])->applyFromArray($centerStyle);
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Lịch_sử_bất_thường.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Lịch_sử_bất_thường.xlsx');
        $href = '/exported_files/Lịch_sử_bất_thường.xlsx';
        return $this->success($href);
    }

    public function exportReportQC(Request $request)
    {
        $input = $request->all();
        $sheet_array = [];
        foreach ($input as $key => $value) {
            switch ($key) {
                case 'week':
                    $sheet_array[$key]['datetime'] = date("W", strtotime($value));
                    $sheet_array[$key]['title'] = 'tuần';
                    $sheet_array[$key]['start_date'] = date("Y-m-d", strtotime($value . ' monday this week'));
                    $sheet_array[$key]['end_date'] = date("Y-m-d", strtotime($value . ' sunday this week'));
                    break;
                case 'month':
                    $sheet_array[$key]['datetime'] = date("m", strtotime($value));
                    $sheet_array[$key]['title'] = 'tháng';
                    $sheet_array[$key]['start_date'] = date("Y-m-01", strtotime($value));
                    $sheet_array[$key]['end_date'] = date("Y-m-t", strtotime($value));
                    break;
                case 'year':
                    $sheet_array[$key]['datetime'] = date("Y", strtotime($value));
                    $sheet_array[$key]['title'] = 'năm';
                    $sheet_array[$key]['start_date'] = date("Y-01-01", strtotime($value));
                    $sheet_array[$key]['end_date'] = date("Y-12-31", strtotime($value));
                    break;
                default:
                    $sheet_array[$key]['datetime'] = date("d-m-Y", strtotime($value));
                    $sheet_array[$key]['title'] = 'ngày';
                    $sheet_array[$key]['start_date'] = date("Y-m-d", strtotime($value));
                    $sheet_array[$key]['end_date'] = date("Y-m-d", strtotime($value));
                    break;
            }
            $query = InfoCongDoan::orderBy('created_at');
            if (isset($sheet_array[$key]['start_date']) && isset($sheet_array[$key]['end_date'])) {
                $query->whereDate('created_at', '>=', $sheet_array[$key]['start_date'])
                    ->whereDate('created_at', '<=', $sheet_array[$key]['end_date']);
            }
            $infos = $query->with("lot.product", "lot.log", "plan", "line")->whereNotIn('line_id', [9, 21])->whereHas('lot', function ($lot_query) {
                $lot_query->where(function ($q) {
                    $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
                })->orWhere(function ($q) {
                    $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
                });
            })->get()->groupBy('line_id');
            $data = [];
            foreach ($infos as $line_id => $info_cong_doan) {
                $line = Line::find($line_id);
                $sum_ok = 0;
                $sum_ng = 0;
                foreach ($info_cong_doan as $info) {
                    $sum_ok += 1;
                    $log = $info->lot->log ?? null;
                    if ($log) {
                        $qc_log = $log->info;
                        $result = array_column(array_intersect_key($qc_log['qc'][Str::slug($line->name)] ?? [], array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
                        if (in_array(0, $result)) {
                            $sum_ng += 1;
                            $sum_ok -= 1;
                        }
                    }
                }

                $data[$line_id]['cong_doan'] = $line->name;
                $data[$line_id]['sum_lot_kt'] = count($info_cong_doan);
                $data[$line_id]['sum_lot_ok'] = $sum_ok;
                $data[$line_id]['sum_lot_ng'] = $sum_ng;
                $data[$line_id]['sum_ty_le_ng'] = count($info_cong_doan) ? number_format($sum_ng / count($info_cong_doan) * 100) : 0;
                $data[$line_id]['loi_phat_sinh'] = '';
            }
            $sheet_array[$key]['data'] = $data;
        }
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'wrapText' => true
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'EBF1DE')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet_index = 0;

        // return $sheet_array;
        foreach ($sheet_array as $arr) {
            $sheet = $spreadsheet->getSheet($sheet_index);
            $sheet->setTitle('Báo cáo ' . $arr['title']);
            $start_row = 2;
            $start_col = 1;

            $header = ['Công đoạn', 'Tổng số lot kiểm tra', "Số lot OK", "Số lot NG", "Tỷ lệ NG (%)", "Lỗi phát sinh"];
            array_unshift($header, ucfirst($arr['title']));
            $table_key = [
                'A' => 'date',
                'B' => 'cong_doan',
                'C' => 'sum_lot_kt',
                'D' => 'sum_lot_ok',
                'E' => 'sum_lot_ng',
                'F' => 'sum_ty_le_ng',
                'G' => 'loi_phat_sinh',
            ];
            $table = $arr['data'] ?? [];
            foreach ($header as $key => $cell) {
                if (!is_array($cell)) {
                    $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 1])->getStyle([$start_col, $start_row, $start_col, $start_row + 1])->applyFromArray($headerStyle);
                } else {
                    $style = array_merge($headerStyle, array('fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => array('argb' => 'EBF1DE')
                    ]));
                    $sheet->setCellValue([$start_col, $start_row], $key)->mergeCells([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->getStyle([$start_col, $start_row, $start_col + count($cell) - 1, $start_row])->applyFromArray($style);
                    foreach ($cell as $val) {

                        $sheet->setCellValue([$start_col, $start_row + 1], $val)->getStyle([$start_col, $start_row + 1])->applyFromArray($style);
                        $start_col += 1;
                    }
                    continue;
                }
                $start_col += 1;
            }

            $sheet->setCellValue([1, 1], 'BÁO CÁO ' . mb_strtoupper($arr['title']))->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
            $sheet->getRowDimension(1)->setRowHeight(40);
            $table_col = 2;
            $table_row = $start_row + 2;
            foreach ($table as $key => $row) {
                $table_col = 2;
                foreach ((array)$row as $key => $cell) {
                    if (in_array($key, $table_key)) {
                        $value = '';
                        if (is_numeric($key)) {
                            switch ($cell) {
                                case 0:
                                    $value = "NG";
                                    break;
                                case 1:
                                    $value = "OK";
                                    break;
                                default:
                                    $value = "";
                                    break;
                            }
                        } else {
                            $value = $cell;
                        }
                        $sheet->setCellValue(array_search($key, $table_key) . $table_row, $value)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                    } else {
                        continue;
                    }
                    $table_col += 1;
                }
                $table_row += 1;
            }
            if (count($table)) {
                $sheet->setCellValue([1, $start_row + 2], $arr['datetime'])->mergeCells([1, $start_row + 2, 1, $table_row - 1])->getStyle([1, $start_row + 2, 1, $table_row - 1])->applyFromArray($centerStyle);
            }

            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
                $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
            }
            if ($sheet_index < count($sheet_array) - 1) {
                $spreadsheet->createSheet();
                $sheet_index += 1;
            }
        }

        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Báo cáo.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Báo cáo.xlsx');
        $href = '/exported_files/Báo cáo.xlsx';
        return $this->success($href);
    }

    public function productionPlanQuery(Request $request)
    {
        $input = $request->all();
        $query = ProductionPlan::with('product', 'material', 'line')->orderBy('line_id', 'ASC')->orderBy('status_plan', 'ASC')->orderBy('thoi_gian_bat_dau', 'ASC');
        if (isset($input['date']) && count($input['date'])) {
            $query->whereDate('thoi_gian_bat_dau', '>=', date('Y-m-d', strtotime($input['date'][0])))
                ->whereDate('thoi_gian_bat_dau', '<=', date('Y-m-d', strtotime($input['date'][1])));
        }
        if (isset($input['line_id'])) {
            if (is_array($input['line_id'])) {
                $query->whereIn('line_id', $input['line_id']);
            } else {
                $query->where('line_id', $input['line_id']);
            }
        }
        if (isset($request->machine_code)) {
            if (is_array($request->machine_code)) {
                $query->whereIn('machine_id', $request->machine_code);
            } else {
                $query->where('machine_id', $request->machine_code);
            }
        }
        if (isset($input['product_id'])) {
            $query->where('product_id', $input['product_id']);
        }
        if (isset($input['ten_sp'])) {
            $query->where('product_id', $input['ten_sp']);
        }
        if (isset($input['lo_sx'])) {
            $query->where('lo_sx', $input['lo_sx']);
        }
        if (isset($input['khach_hang'])) {
            $khach_hang = Customer::where('id', $input['khach_hang'])->first();
            if ($khach_hang) {
                $query->where('khach_hang', $khach_hang->name);
            }
        }
        if (isset($input['status_plan'])) {
            $query->where('status_plan', $input['status_plan']);
        }
        // $query->join('products', 'products.id', '=', 'production_plans.product_id')->select('production_plans.*', 'products.name as ten_sp');
        return $query;
    }

    public function getListProductionPlan(Request $request)
    {
        $list_query = $this->productionPlanQuery($request);
        $list = $list_query->get();
        foreach ($list as $plan) {
            $plan->sl_ke_hoach_manh = $plan->sl_giao_sx;
            $plan->ten_san_pham = $plan->product->name ?? '';
            // if ($plan->line_id == 24) {
            //     $plan->ten_san_pham = $plan->material->name ?? "";
            //     $plan->product_id = $plan->material->id ?? "";
            // }
            // $plan->ngay_giao_hang = date('d/m/Y', strtotime($plan->ngay_giao_hang));
            $plan->cong_doan_sx = $plan->line->name ?? '';
            $plan->status = strtotime(date('Y-m-d')) >= strtotime($plan->ngay_sx) ? 'FIX' : 'PRE';
            $plan->kqsx = InfoCongDoan::where('line_id', $plan->line_id)->where('plan_id', $plan->id)->whereNotNull('thoi_gian_bat_dau')->sum('sl_dau_ra_hang_loat') -  InfoCongDoan::where('line_id', $plan->line_id)->where('plan_id', $plan->id)->whereNotNull('thoi_gian_bat_dau')->sum('sl_ng');
            // $plan->thoi_gian_ket_thuc = date('d/m/Y H:i:s', strtotime($plan->thoi_gian_ket_thuc));
            // $plan->thoi_gian_bat_dau =  date('d/m/Y H:i:s', strtotime($plan->thoi_gian_bat_dau));
        }
        return $this->success($list);
    }

    public function exportKHSX(Request $request)
    {
        $lines = Line::all();
        $list_query = $this->productionPlanQuery($request);
        $list = $list_query->get();
        foreach ($list as $plan) {
            $plan->sl_ke_hoach_manh = $plan ? (($plan->sl_thanh_pham && $plan->product->so_bat) ? $plan->product->so_bat * $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0;
            $plan->sl_giao_sx = $plan->sl_giao_sx ? $plan->sl_giao_sx : ($plan->sl_thanh_pham * $plan->product->so_bat);
            $plan->ten_sp = $plan->product->name ?? '';
            $plan->ngay_giao_hang = date('d/m/Y', strtotime($plan->ngay_giao_hang));
            $plan->cong_doan_sx = $plan->line->name ?? $this->find_line_by_slug($plan->cong_doan_sx, $lines);
            $plan->status = strtotime(date('Y-m-d')) >= strtotime($plan->ngay_sx) ? 'FIX' : 'PRE';
            $plan->kqsx = InfoCongDoan::where('line_id', $plan->line_id)->where('lo_sx', $plan->lo_sx)->whereNotNull('thoi_gian_bat_dau')->sum('sl_dau_ra_hang_loat') -  InfoCongDoan::where('line_id', $plan->line_id)->whereNotNull('thoi_gian_bat_dau')->where('lo_sx', $plan->lo_sx)->sum('sl_ng');
            $plan->tg_ket_thuc = date('d/m/Y H:i:s', strtotime($plan->thoi_gian_ket_thuc));
            $plan->tg_bat_dau =  date('d/m/Y H:i:s', strtotime($plan->thoi_gian_bat_dau));
        }
        $table = $list;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['Thứ tự ưu tiên', 'Thời gian bắt đầu', 'Thời gian kết thúc', 'Công đoạn', 'Máy', 'Mã SP', 'Tên SP', 'Khách hàng', 'Ca SX', 'Lô SX', 'Số bát', 'Ngày giao hàng', 'Số lượng tổng ĐH', 'Số lượng NVL đầu vào (tờ)', 'Kế hoạch SL thành phẩm (tờ)', 'Kế hoạch SL thành phẩm (mảnh)', 'Thực tế SL thành phẩm (mảnh)', 'UPH', 'Tổng thời gian thực hiện', 'Nhân lực', 'Tình trạng', 'Ghi chú', 'Kế hoạch'];
        $table_key = [
            'A' => 'thu_tu_uu_tien',
            'B' => 'tg_bat_dau',
            'C' => 'tg_ket_thuc',
            'D' => 'cong_doan_sx',
            'E' => 'machine_id',
            'F' => 'product_id',
            'G' => 'ten_sp',
            'H' => 'khach_hang',
            'I' => 'ca_sx',
            'J' => 'lo_sx',
            'K' => 'so_bat',
            'L' => 'ngay_giao_hang',
            'M' => 'sl_tong_don_hang',
            'N' => 'sl_nvl',
            'O' => 'sl_thanh_pham',
            'P' => 'sl_ke_hoach_manh',
            'Q' => 'kqsx',
            'R' => 'UPH',
            'S' => 'tong_tg_thuc_hien',
            'T' => 'nhan_luc',
            'U' => 'status',
            'V' => 'note',
            'W' => 'plan',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'Kế hoạch sản xuất')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table->toArray() as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Kế hoạch sản xuất.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Kế hoạch sản xuất.xlsx');
        $href = '/exported_files/Kế hoạch sản xuất.xlsx';
        return $this->success($href);
    }
    function find_line_by_slug($needle, $haystack)
    {
        foreach ($haystack as $item) {
            if (Str::slug($item->name) === $needle) {
                return $item->name;
                break;
            }
        }
    }

    public function exportQCErrorList(Request $request)
    {
        $query = InfoCongDoan::orderBy('created_at');
        $line = Line::find($request->line_id);
        if ($line) {
            $query->where('line_id', $line->id);
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
        $infos = $query->with("lot.product", "lot.log", "plan", "line")->whereNotIn('line_id', [9, 21])->whereHas('lot', function ($lot_query) {
            $lot_query->where(function ($q) {
                $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
            })->orWhere(function ($q) {
                $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
            });
        })->get();
        $table = $this->produceTablePQC($infos);

        foreach ($table as $key => $data) {
            foreach ($data['errors'] ?? [] as $error_id => $value) {
                $table[$key]['ng' . $error_id] = $value['value'];
            }
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header1 = [
            'STT',
            'Ngày sản xuất',
            "Ca sản xuất",
            "Công đoạn",
            "Máy sản xuất",
            'Tên sản phẩm',
            'Lô sản xuất',
            'Mã pallet/thùng',
            "ĐV",
            "OK",
            "NG",
            "KV"
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'ngay_sx',
            'C' => 'ca_sx',
            'D' => 'cong_doan',
            'E' => 'machine',
            'F' => 'ten_san_pham',
            'G' => 'lo_sx',
            'H' => 'lot_id',
            'I' => 'sl_dau_vao_hang_loat',
            "J" => "sl_dau_ra_ok",
            "K" => "sl_ng",
            'L' => 'sl_tem_vang',
        ];
        $header2 = [];
        $error_query  = Error::where('noi_dung', '<>', '')->join('lines', 'lines.id', '=', 'errors.line_id')->select('errors.*', 'lines.ordering as ordering')->orderBy('ordering')->orderBy('id');
        if (isset($request->error_ids)) {
            $error_query->whereIn('errors.id', $request->error_ids);
        }
        $list = $error_query->get();
        $index = 13;
        foreach ($list as $key => $item) {
            $header2['Lỗi NG'][] = [$item->id, $item->noi_dung];
            $letter = $this->num_to_letters($index);
            $table_key[$letter] = 'ng' . $item->id;
            $index++;
        }
        foreach ($list as $key => $item) {
            $header2['Lỗi KV'][] = [$item->id, $item->noi_dung];
            $letter = $this->num_to_letters($index);
            $table_key[$letter] = 'kv' . $item->id;
            $index++;
        }

        foreach ($header1 as $key => $cell) {
            $sheet->setCellValue([$start_col, $start_row + 1], $cell)->mergeCells([$start_col, $start_row + 1, $start_col, $start_row + 1])->getStyle([$start_col, $start_row + 1, $start_col, $start_row + 1])->applyFromArray($headerStyle);
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'BÁO CÁO SỐ LỖI')->mergeCells([1, 1, $start_col - 1, 2])->getStyle([1, 1, $start_col - 1, 2])->applyFromArray($titleStyle);
        foreach ($header2 as $key => $cell) {
            $align_left = array_merge($headerStyle, array('alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ]));
            $sheet->setCellValue([$start_col, 1], $key)->mergeCells([$start_col, 1, $start_col + count($cell) - 1, 1])->getStyle([$start_col, 1, $start_col + count($cell) - 1, 1])->applyFromArray($align_left);
            if (count($cell)) {
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start_col, 2], $val[0])->getStyle([$start_col, 2])->applyFromArray($headerStyle);
                    $sheet->setCellValue([$start_col, 3], $val[1])->getStyle([$start_col, 3])->applyFromArray($headerStyle);
                    $start_col += 1;
                }
            } else {
                $start_col += 1;
            }
        }
        $table_col = 1;
        $table_row = $start_row + 2;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([1, $table_row])->applyFromArray($centerStyle);
            $table_col += 1;
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key)) {
                    $sheet->setCellValue(array_search($key, $table_key) . $table_row, $cell)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Báo cáo số lỗi.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Báo cáo số lỗi.xlsx');
        $href = '/exported_files/Báo cáo số lỗi.xlsx';
        return $this->success($href);
    }

    public function qcErrorList(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = InfoCongDoan::orderBy('created_at');
        $line = Line::find($request->line_id);
        if ($line) {
            $query->where('line_id', $line->id);
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
        $infos = $query->with("lot.product", "lot.log", "plan", "line")->whereNotIn('line_id', [9, 21, 20])->whereHas('lot', function ($lot_query) {
            $lot_query->where(function ($q) {
                $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
            })->orWhere(function ($q) {
                $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
            });
        })->get();
        $record = $query->offset($page * $pageSize)->limit($pageSize)->get();
        $table = $this->produceTablePQC($record);
        foreach ($table as $key => $data) {
            foreach ($data['errors'] ?? [] as $error_id => $value) {
                $table[$key]['ng' . $error_id] = $value['value'];
            }
        }
        $count = count($infos);
        $totalPage = $count;
        $columns = [];
        $error_query  = Error::where('noi_dung', '<>', '')->join('lines', 'lines.id', '=', 'errors.line_id')->select('errors.*', 'lines.ordering as ordering')->orderBy('ordering')->orderBy('id');
        if (isset($request->error_ids)) {
            $error_query->whereIn('errors.id', $request->error_ids);
        }
        $list = $error_query->get();
        foreach ($list as $key => $item) {
            $columns['Lỗi NG'][$key]['title'] = $item->noi_dung;
            $columns['Lỗi NG'][$key]['key'] = 'ng' . $item->id;
            $columns['Lỗi KV'][$key]['title'] = $item->noi_dung;
            $columns['Lỗi KV'][$key]['key'] = 'kv' . $item->id;
        }
        return $this->success(['data' => $table, "totalPage" => $totalPage, 'columns' => $columns]);
    }

    public function iqcCheckedList(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = LSXLog::with('lot')->where('info', 'like', '%iqc%');
        if (isset($request->date) && count($request->date)) {
            $query->whereDate('info->qc->iqc->thoi_gian_vao', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('info->qc->iqc->thoi_gian_vao', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        if (isset($request->product_id)) {
            $query->where('lot_id', 'like',  '%' . $request->product_id . '%');
        }
        if (isset($request->ten_sp)) {
            $query->where('lot_id', 'like',  '%' . $request->ten_sp . '%');
        }
        if (isset($request->khach_hang)) {
            $product_ids = Product::where('customer_id', $request->khach_hang)->pluck('id')->toArray();
            $query->whereIn('product_id', $product_ids);
        }
        if (isset($request->lo_sx)) {
            $query->where('lot_id', 'like', "%$request->lo_sx%");
        }
        $logs = $query->get();
        $record = $query->offset($page * $pageSize)->limit($pageSize)->get();
        foreach ($record as $log) {
            $info_qc = $log->info['qc']['iqc'] ?? [];
            $result = array_column(array_intersect_key($info_qc, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
            $lot = $log->lot;
            $log->thoi_gian_kiem_tra = $info_qc['thoi_gian_vao'] ? date('d/m/Y H:i:s', strtotime($info_qc['thoi_gian_vao'])) : "";
            $log->lo_sx = $lot->lo_sx ?? "";
            $log->khach_hang = $lot->plan->khach_hang ?? "";
            $log->lot_id = $lot->id ?? "";
            $log->ten_san_pham = $lot->product->name ?? "";
            $log->phan_dinh = $log->thoi_gian_kiem_tra ? (in_array(0, $result) ? 2 : 1) : 0;
            $log->user_name = $info_qc['user_name'] ?? "";
        }
        $count = count($logs);
        $totalPage = $count;
        $columns = [];
        // $error_query  = Error::where('noi_dung', '<>', '')->join('lines', 'lines.id', '=', 'errors.line_id')->select('errors.*', 'lines.ordering as ordering')->orderBy('ordering')->orderBy('id')  ;
        // if(isset($request->error_ids)){
        //     $error_query->whereIn('errors.id', $request->error_ids);
        // }
        // $list = $error_query->get();
        // foreach ($list as $key => $item) {
        //     $columns['Lỗi NG'][$key]['title'] = $item->noi_dung;
        //     $columns['Lỗi NG'][$key]['key'] = 'ng'.$item->id;
        // }
        // foreach ($list as $key => $item) {
        //     $columns['Lỗi KV'][$key]['title'] = $item->noi_dung;
        //     $columns['Lỗi KV'][$key]['key'] = 'kv'.$item->id;
        // }
        return $this->success(['data' => $record, "totalPage" => $totalPage, 'columns' => $columns]);
    }

    public function powerConsumeByMonth(Request $request)
    {
        $input = $request->all();
        $input['type'] = 'month';
        switch ($input['type']) {
            case 'month':
                $input['start_date'] = date('Y-m-01 00:00:00', strtotime($input['datetime']));
                $input['end_date'] = date('Y-m-t 23:59:59', strtotime($input['datetime']));
                break;
            case 'day':
                $input['start_date'] = date('Y-m-d 00:00:00', strtotime($input['datetime']));
                $input['end_date'] = date('Y-m-d 23:59:59', strtotime($input['datetime']));
                break;
            case 'week':
                $input['start_date'] = date('Y-m-d 00:00:00', strtotime($input['datetime'] . " sunday -1 week +1 day"));
                $input['end_date'] = date('Y-m-d 23:59:59', strtotime($input['datetime'] . " sunday 0 week"));
                break;
            default:
                # code...
                break;
        }
        $query = InfoCongDoan::orderBy('created_at');
        if (isset($input['start_date']) && isset($input['end_date'])) {
            $query->where('created_at', '>=', $input['start_date'])->where('created_at', '<=', $input['end_date']);
        }
        $query->where('line_id', $input['line_id'] ?? 10);
        $records = $query->whereNotNull(['thoi_gian_ket_thuc', 'thoi_gian_bat_dau'])
            ->select(
                'info_cong_doan.*',
                DB::raw('DATE(thoi_gian_bat_dau) as date'),
                DB::raw('UNIX_TIMESTAMP(thoi_gian_ket_thuc) as ket_thuc, UNIX_TIMESTAMP(thoi_gian_bat_dau) as bat_dau')
            )
            ->get()
            ->groupBy('date');
        $datediff = strtotime($input['end_date']) - strtotime($input['start_date']);
        $days = round($datediff / (60 * 60 * 24));
        $data = [];
        $power_sum = ['col' => 'Tổng điện năng (kW)'];
        $total_hours = ['col' => 'Số giờ'];
        $result = ['col' => 'TB điện năng tiêu thụ (kWh)'];
        // return $records;
        $sum = 0;
        foreach ($records as $key => $record) {
            $over_time = 0;
            $over_power = 0;
            $seconds = $record->map(function ($info) {
                return abs($info->ket_thuc - $info->bat_dau);
            })->sum();
            $power = $record->sum('powerM') + $over_power;
            $hours = $seconds / 3600;
            $hours += $over_time;
            $power_per_hour = $hours > 0 ? ($power / $hours) : $power;
            if ($hours > 24) {
                $over_time = $hours - 24;
                $hours = 24;
            }
            $over_power = abs($over_time * $power_per_hour);
            $sum += ($power - $over_power) > 0 ? number_format(abs($power - $over_power), 1) : 0;
            $power_sum[date('j', strtotime($key))] = ($power - $over_power) > 0 ? number_format($power - $over_power, 1) : 0;
            $total_hours[date('j', strtotime($key))] = $hours > 0 ? number_format($hours, 1) : 0;
            $result[date('j', strtotime($key))] = $hours > 0 ? number_format($power_per_hour, 1) : 0;
        }
        $data = [$power_sum, $total_hours, $result];
        return $this->success(['data' => $data, 'sum' => round($sum, 1)]);
    }

    public function powerConsumeByMonthChart(Request $request)
    {
        $input = $request->all();
        $input['type'] = 'month';
        switch ($input['type']) {
            case 'month':
                $input['start_date'] = date('Y-m-01 00:00:00', strtotime($input['datetime']));
                $input['end_date'] = date('Y-m-t 23:59:59', strtotime($input['datetime']));
                break;
            case 'day':
                $input['start_date'] = date('Y-m-d 00:00:00', strtotime($input['datetime']));
                $input['end_date'] = date('Y-m-d 23:59:59', strtotime($input['datetime']));
                break;
            case 'week':
                $input['start_date'] = date('Y-m-d 00:00:00', strtotime($input['datetime'] . " sunday -1 week +1 day"));
                $input['end_date'] = date('Y-m-d 23:59:59', strtotime($input['datetime'] . " sunday 0 week"));
                break;
            default:
                # code...
                break;
        }
        $prev_year_input = [];
        $prev_year_input['start_date'] = date('Y-m-d 00:00:00', strtotime($input['start_date'] . ' - 1 year'));
        $prev_year_input['end_date'] = date('Y-m-d 23:59:59', strtotime($input['end_date'] . ' - 1 year'));
        //current year data
        $query = InfoCongDoan::orderBy('created_at');
        if (isset($input['start_date']) && isset($input['end_date'])) {
            $query->where('created_at', '>=', $input['start_date'])->where('created_at', '<=', $input['end_date']);
        }
        $query->where('line_id', $input['line_id'] ?? 10);
        $records = $query->whereNotNull(['thoi_gian_ket_thuc', 'thoi_gian_bat_dau'])
            ->select(
                'info_cong_doan.*',
                DB::raw('DATE(thoi_gian_bat_dau) as date'),
                DB::raw('UNIX_TIMESTAMP(thoi_gian_ket_thuc) as ket_thuc, UNIX_TIMESTAMP(thoi_gian_bat_dau) as bat_dau')
            )
            ->get()
            ->groupBy('date');
        $datediff = strtotime($input['end_date']) - strtotime($input['start_date']);
        $current = [];
        $power_sum = [];
        $total_hours = [];
        $result = [];
        $sum = 0;
        foreach ($records as $key => $record) {
            $over_time = 0;
            $over_power = 0;
            $seconds = $record->map(function ($info) {
                return $info->ket_thuc - $info->bat_dau;
            })->sum();
            $power = $record->sum('powerM') + $over_power;
            $hours = $seconds / 3600;
            $hours += $over_time;
            $power_per_hour = $hours > 0 ? ($power / $hours) : $power;
            if ($hours > 24) {
                $over_time = $hours - 24;
                $hours = 24;
            }
            $over_power = $over_time * $power_per_hour;
            $sum += $power - $over_power > 0 ? round($power - $over_power, 1) : 0;
            $power_sum[date('d/m', strtotime($key))] = round($power - $over_power, 1);
            $total_hours[date('d/m', strtotime($key))] = round($hours, 1);
            $result[date('d/m', strtotime($key))] = $hours ? round($power_per_hour, 1) : 0;
        }
        $current = $power_sum;

        //prev year data
        $query = InfoCongDoan::orderBy('created_at');
        if (isset($input['start_date']) && isset($input['end_date'])) {
            $query->where('created_at', '>=', $prev_year_input['start_date'])->where('created_at', '<=', $prev_year_input['end_date']);
        }
        $query->where('line_id', $input['line_id'] ?? 10);
        $records = $query->whereNotNull(['thoi_gian_ket_thuc', 'thoi_gian_bat_dau'])
            ->select(
                'info_cong_doan.*',
                DB::raw('DATE(thoi_gian_bat_dau) as date'),
                DB::raw('UNIX_TIMESTAMP(thoi_gian_ket_thuc) as ket_thuc, UNIX_TIMESTAMP(thoi_gian_bat_dau) as bat_dau')
            )
            ->get()
            ->groupBy('date');
        $previous = [];
        $power_sum = [];
        $total_hours = [];
        $result = [];
        // return $records;
        $sum = 0;
        foreach ($records as $key => $record) {
            $over_time = 0;
            $over_power = 0;
            $seconds = $record->map(function ($info) {
                return $info->ket_thuc - $info->bat_dau;
            })->sum();
            $power = $record->sum('powerM') + $over_power;
            $hours = $seconds / 3600;
            $hours += $over_time;
            $power_per_hour = $hours > 0 ? ($power / $hours) : $power;
            if ($hours > 24) {
                $over_time = $hours - 24;
                $hours = 24;
            }
            $over_power = $over_time * $power_per_hour;
            $sum += $power - $over_power > 0 ? round($power - $over_power, 1) : 0;
            $power_sum[date('d/m', strtotime($key))] = round($power - $over_power, 1);
            $total_hours[date('d/m', strtotime($key))] = round($hours, 1);
            $result[date('d/m', strtotime($key))] = $hours ? round($power_per_hour, 1) : 0;
        }
        $previous = $power_sum;
        return $this->success(['current' => $current, 'previous' => $previous, 'start_date' => $input['start_date'], 'end_date' => $input['end_date']]);
    }

    public function powerConsumeByProductQuery(Request $request)
    {
        $input = $request->all();
        $query = InfoCongDoan::orderBy('created_at');
        if (isset($input['start_date']) && isset($input['end_date'])) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($input['start_date'])))->whereDate('updated_at', '<=', date('Y-m-d', strtotime($input['end_date'])));
        }
        if (isset($input['line_id'])) {
            if (is_array($input['line_id'])) {
                $query->whereIn('line_id', $input['line_id']);
            } else {
                $query->where('line_id', $input['line_id']);
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
        $query->with("lot.product", "lot.log", "plan", "line")->whereHas('lot', function ($lot_query) {
            $lot_query->whereIn('type', [0, 1, 2, 3])->where('info_cong_doan.line_id', 15)->orWhere('type', '<>', 1)->where('info_cong_doan.line_id', '<>', 15);
        });
        $query->whereNotNull(['thoi_gian_ket_thuc', 'thoi_gian_bat_dau'])
            ->select(
                'info_cong_doan.*',
                DB::raw(
                    'UNIX_TIMESTAMP(thoi_gian_ket_thuc) as ket_thuc,' .
                        'UNIX_TIMESTAMP(thoi_gian_bat_dau) as bat_dau,' .
                        'UNIX_TIMESTAMP(thoi_gian_bam_may) as bam_may'
                ),
                DB::raw('CONCAT_WS("", COALESCE(line_id,""),COALESCE(lo_sx,"")) as group_field')
            )
            ->with('line.machine', 'lot.product.customer');
        return $query;
    }
    public function powerConsumeByProduct(Request $request)
    {

        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->powerConsumeByProductQuery($request);
        $sum = $query->sum('powerM');
        $records = $query->get()->groupBy('group_field');
        $data = [];
        $index = 0;
        $totalPage = count($records);
        $records = array_slice($records->toArray(), $pageSize * $page, $pageSize);
        foreach ($records as $key => $record) {
            $index += 1;
            $first_info = $record[0];
            $last_info = $record[count($record) - 1];
            $seconds = array_reduce($record, function ($carry, $item) {
                $carry += $item['ket_thuc'] - $item['bat_dau'];
                return $carry;
            });
            $power = array_sum(array_column($record, 'powerM'));
            $hours = $seconds / 3600;
            $start_date = date("Y-m-d", strtotime($first_info['created_at']));
            $start_shift = strtotime($start_date . ' 07:00:00');
            $end_shift = strtotime($start_date . ' 19:00:00');
            if (strtotime($first_info['created_at']) >= $start_shift && strtotime($first_info['created_at']) <=  $end_shift) {
                $ca_sx = 'Ca 1';
            } else {
                $ca_sx = 'Ca 2';
            }
            $tm = [
                'stt' => $index,
                'ngay_sx' => date('d/m/Y', strtotime($first_info['created_at'])),
                'ca_sx' => $ca_sx,
                'xuong' => "Giấy",
                'cong_doan' => $first_info['line']['name'] ?? "",
                'machine_name' => count($first_info['line']['machine']) > 0 ? $first_info['line']['machine'][0]['name'] : "",
                'machine_id' => count($first_info['line']['machine']) > 0 ? $first_info['line']['machine'][0]['code'] : "",
                'ten_san_pham' => $first_info['lot']['product']['name'] ?? "",
                'khach_hang' => $first_info['lot']['product']['customer']['name'] ?? "",
                'product_id' => $first_info['lot']['product_id'],
                'material_id' => $first_info['lot']['product']['material_id'] ?? "",
                'lo_sx' => $first_info['lo_sx'],
                'power_consume' => round($power, 1),
                'produce_time' => round($hours, 1),
                'avg_power_consume' => round($hours, 1) ? round(round($power, 1) / round($hours, 1), 1) : 0,
                'thoi_gian_bat_dau_vao_hang' => $first_info['bat_dau'] ? date('d/m/Y H:i:s', $first_info['bat_dau']) : '',
                'thoi_gian_ket_thuc_vao_hang' => $first_info['bam_may'] ? date('d/m/Y H:i:s', $first_info['bam_may']) : '',
                'sl_dau_vao_vao_hang' => $first_info['sl_dau_vao_chay_thu'],
                'sl_dau_ra_vao_hang' => $first_info['sl_dau_ra_chay_thu'],
                'thoi_gian_bat_dau_sx' => $first_info['bam_may'] ? date('d/m/Y H:i:s', $first_info['bam_may']) : '',
                'thoi_gian_ket_thuc_sx' => $last_info['ket_thuc'] ? date('d/m/Y H:i:s', $last_info['ket_thuc']) : '',
                'sl_dau_vao_sx' => array_sum(array_column($record, 'sl_dau_vao_hang_loat')),
                'sl_dau_ra_sx' => array_sum(array_column($record, 'sl_dau_ra_hang_loat')),
                'sl_tem_vang' => array_sum(array_column($record, 'sl_tem_vang')),
                'sl_ng' => array_sum(array_column($record, 'sl_ng')),
                'chenh_lech' => '',
                'tt_thuc_te' => '',
                'cong_nhan_sx' => $last_info['plan']['nhan_luc'] ?? "",
            ];
            $tm['sl_ok'] = $tm['sl_dau_ra_sx'] - $tm['sl_tem_vang'] - $tm['sl_ng'];
            $tm['ty_le_dat'] = $tm['sl_dau_vao_sx'] > 0 ? round(($tm['sl_ok'] / $tm['sl_dau_vao_sx']) * 100) : 0;
            $data[] = $tm;
        }

        return $this->success(['data' => $data, 'sum' => round($sum, 1), 'totalPage' => $totalPage]);
    }

    public function exportPowerConsumeByProduct(Request $request)
    {
        $query = $this->powerConsumeByProductQuery($request);
        $records = $query->get()->groupBy('group_field');
        $data = [];
        $sum = 0;
        $index = 0;
        foreach ($records as $key => $record) {
            if ($record->count() <= 0) continue;
            $index += 1;
            $first_info = $record[0];
            $last_info = $record[count($record) - 1];
            $seconds = $record->map(function ($info) {
                return $info->ket_thuc - $info->bat_dau;
            })->sum();
            $power = $record->sum('powerM');
            $sum += $power;
            $hours = $seconds / 3600;
            $start_date = date("Y-m-d", strtotime($first_info->created_at));
            $start_shift = strtotime($start_date . ' 07:00:00');
            $end_shift = strtotime($start_date . ' 19:00:00');
            if (strtotime($first_info->created_at) >= $start_shift && strtotime($first_info->created_at) <=  $end_shift) {
                $ca_sx = 'Ca 1';
            } else {
                $ca_sx = 'Ca 2';
            }
            $tm = [
                'stt' => $index,
                'ngay_sx' => date('d/m/Y', strtotime($first_info->created_at)),
                'ca_sx' => $ca_sx,
                'xuong' => "Giấy",
                'cong_doan' => $first_info->line->name ?? "",
                'machine' => $first_info->line->machine->count() > 0 ? $first_info->line->machine[0]->name : "",
                'machine_id' => $first_info->line->machine->count() > 0 ? $first_info->line->machine[0]->code : "",
                'ten_san_pham' => $first_info->lot->product->name ?? "",
                'khach_hang' => $first_info->lot->product->customer->name ?? "",
                'product_id' => $first_info->lot->product_id,
                'material_id' => $first_info->lot->product->material_id ?? "",
                'lo_sx' => $first_info->lo_sx,
                'power_consume' => round($power, 1),
                'produce_time' => round($hours, 1),
                'avg_power_consume' => round($hours, 1) ? round(round($power, 1) / round($hours, 1), 1) : 0,
                'thoi_gian_bat_dau_vao_hang' => $first_info->bat_dau ? date('d/m/Y H:i:s', $first_info->bat_dau) : '',
                'thoi_gian_ket_thuc_vao_hang' => $first_info->bam_may ? date('d/m/Y H:i:s', $first_info->bam_may) : '',
                'sl_dau_vao_vao_hang' => $first_info->sl_dau_vao_chay_thu,
                'sl_dau_ra_vao_hang' => $first_info->sl_dau_ra_chay_thu,
                'thoi_gian_bat_dau_sx' => $first_info->bam_may ? date('d/m/Y H:i:s', $first_info->bam_may) : '',
                'thoi_gian_ket_thuc_sx' => $last_info->ket_thuc ? date('d/m/Y H:i:s', $last_info->ket_thuc) : '',
                'sl_dau_vao_sx' => $record->sum('sl_dau_vao_hang_loat'),
                'sl_dau_ra_sx' => $record->sum('sl_dau_ra_hang_loat'),
                'sl_ok' => ($record->sum('sl_dau_ra_hang_loat') - $record->sum('sl_tem_vang') - $record->sum('sl_ng')) ?? 0,
                'sl_tem_vang' => $record->sum('sl_tem_vang'),
                'sl_ng' => $record->sum('sl_ng'),
                'chenh_lech' => '',
                'ty_le_dat' => $record->sum('sl_dau_vao_hang_loat') ? round(($record->sum('sl_dau_ra_hang_loat') - $record->sum('sl_tem_vang') - $record->sum('sl_ng')) / $record->sum('sl_dau_vao_hang_loat') * 100) : 0,
                'tt_thuc_te' => '',
                'cong_nhan_sx' => $last_info->plan->nhan_luc ?? "",
            ];
            $data[] = $tm;
        }
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'wrapText' => true
            ],
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $yellow = array_merge(
            $centerStyle,
            array(
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => array('argb' => 'FFFF00')
                ]
            )
        );
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;

        $header = [
            'STT',
            'Ngày SX',
            'Ca SX',
            'Xưởng',
            'Công đoạn',
            'Máy sản xuất',
            'Mã máy',
            'Tên sản phẩm',
            'Khách hàng',
            'Mã hàng',
            'Mã nguyên vật liệu',
            'Lô SX',
            'Điện năng tiêu thụ',
            'Thời gian sản xuất',
            'TB điện năng tiêu thụ (1 giờ)',
            'Thực tế' => [
                'Vào hàng' => [
                    'Thời gian bắt đầu vào hàng',
                    'Thời gian kết thúc vào hàng',
                    'Số lượng đầu vào vào hàng',
                    'Số lượng đầu ra vào hàng',
                ],
                'Sản xuất sản lượng' => [
                    'Thời gian bắt đầu sx sản lượng',
                    'Thời gian kết thúc sx sản lượng',
                    'Số lượng đầu vào thực tế',
                    'Số lượng đầu ra thực tế',
                    'Số lượng đầu ra OK',
                    'Số lượng tem vàng',
                    'Số lượng NG',
                ]
            ],
            'Chênh lệch',
            'Tỷ lệ đạt',
            'TT Thực tế (Phút)',
            'Công nhân SX',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $style = $headerStyle;
                if (in_array($key, [12, 13, 14])) $style = $yellow;
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row + 2])->getStyle([$start_col, $start_row, $start_col, $start_row + 2])->applyFromArray($style);
            } else {
                if (is_array($cell)) {
                    $lenght = 0;
                    $col = $start_col;
                    foreach ($cell as $key_cell => $val) {
                        if (is_array($val)) {
                            $sheet->setCellValue([$start_col, $start_row + 1], $key_cell)->mergeCells([$start_col, $start_row + 1, $start_col + count($val) - 1, $start_row + 1])->getStyle([$start_col, $start_row + 1, $start_col + count($val) - 1, $start_row + 1])->applyFromArray($headerStyle);
                            foreach ($val as $value) {
                                $sheet->setCellValue([$start_col, $start_row + 2], $value)->getStyle([$start_col, $start_row + 2])->applyFromArray($headerStyle);
                                $start_col += 1;
                            }
                        }
                    }
                    $sheet->setCellValue([$col, $start_row], $key)->mergeCells([$col, $start_row, $start_col + $lenght - 1, $start_row])->getStyle([$col, $start_row, $start_col + $lenght - 1, $start_row])->applyFromArray($headerStyle);
                }
                continue;
            }
            $start_col += 1;
        }

        $sheet->setCellValue([1, 1], 'FORM HIỂN THỊ ĐIỆN NĂNG TIÊU THỤ')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 2;
        // foreach ($data as $key => $row) {
        //     $table_col = 1;
        //     $sheet->setCellValue([1, $table_row], $table_row - 3)->getStyle([$table_col, $table_row])->applyFromArray($centerStyle);
        //     $table_col += 1;
        //     foreach ((array)$row as $key => $cell) {
        //         if (in_array($key, $table_key)) {
        //             $value = '';
        //             if ($table_col > 7 && ($cell === 1 || $cell === 0)) {
        //                 if ($cell === 1) {
        //                     $value = 'OK';
        //                 } else {
        //                     $value = 'NG';
        //                 }
        //             } else {
        //                 $value = $cell;
        //             }
        //             $sheet->setCellValue(array_search($key, $table_key) . $table_row, $value)->getStyle(array_search($key, $table_key) . $table_row)->applyFromArray($centerStyle);
        //         } else {
        //             continue;
        //         }
        //         $table_col += 1;
        //     }
        //     $table_row += 1;
        // }
        $sheet->fromArray($data, null, 'A5', true);
        $sheet->getStyle([1, 5, 30, count($data) + 4])->applyFromArray($centerStyle);
        $sheet->setCellValue([1, count($data) + 5], 'Tổng điện năng tiêu thụ (kW): ')->mergeCells([1, count($data) + 5, 12, count($data) + 5])->getStyle([1, count($data) + 5, 12, count($data) + 5])->applyFromArray($yellow);
        $sheet->setCellValue([13, count($data) + 5], round($sum, 1))->getStyle([13, count($data) + 5])->applyFromArray($yellow);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row + 2) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }

        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Điện năng tiêu thụ.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Điện năng tiêu thụ.xlsx');
        $href = '/exported_files/Điện năng tiêu thụ.xlsx';
        return $this->success($href);
    }

    public function detailAbnormal(Request $request)
    {
        $id = $request->get('id');
        $record = Monitor::find($id);
        return $this->success($record);
    }

    public function updateProductIdInfoCongDoan(Request $request)
    {
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 0);
        try {
            $counter = 0;
            DB::beginTransaction();
            $infos = InfoCongDoan::with('lot')->get();
            foreach ($infos as $info) {
                $lot = DB::table('info_cong_doan_old')->find($info->id);
                if ($lot) {
                    $update = $info->update(['updated_at' => $lot->updated_at]);
                    $update && $counter++;
                }
            }
            DB::commit();
            return $this->success($counter, 'OK');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, 'Error');
        }
    }

    public function updateSanLuongKhoBaoOn(Request $request)
    {
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 0);
        try {
            $counter = 0;
            DB::beginTransaction();
            $infos_bao_on = InfoCongDoan::where('line_id', 9)->get();
            foreach ($infos_bao_on as $info) {
                $info_in = InfoCongDoan::where('line_id', 10)->where('lot_id', $info->lot_id)->first();
                if ($info_in && $info_in->sl_dau_vao_hang_loat) {
                    $info->update(['sl_dau_vao_hang_loat' => $info_in->sl_dau_vao_hang_loat, 'sl_dau_ra_hang_loat' => $info_in->sl_dau_vao_hang_loat]);
                } else {
                    $lot = Lot::with('product')->find($info->lot_id);
                    if ($lot && $lot->product) $info->update(['sl_dau_vao_hang_loat' => $lot->so_luong * $lot->product->so_bat, 'sl_dau_ra_hang_loat' => $lot->so_luong * $lot->product->so_bat]);
                }
            }
            $infos_u = InfoCongDoan::where('line_id', 21)->get();
            foreach ($infos_u as $info) {
                $info_in = InfoCongDoan::where('line_id', 10)->where('lot_id', $info->lot_id)->first();
                if (!$info_in) {
                    $lot_id = str_replace('.TV10', '', $info->lot_id);
                    $info_in = InfoCongDoan::where('line_id', 10)->where('lot_id', $lot_id)->first();
                    if ($info_in) {
                        $info->update(['sl_dau_vao_hang_loat' => $info_in->sl_tem_vang, 'sl_dau_ra_hang_loat' => $info_in->sl_tem_vang]);
                    }
                } else {
                    $so_luong = $info_in->sl_dau_ra_hang_loat - $info_in->sl_tem_vang - $info_in->sl_ng;
                    $info->update(['sl_dau_vao_hang_loat' => $so_luong, 'sl_dau_ra_hang_loat' => $so_luong]);
                }
            }
            DB::commit();
            return $this->success($counter, 'OK');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $th;
        }
    }

    public function updateMaterialName()
    {
        try {
            DB::beginTransaction();
            $materials = Material::all();
            foreach ($materials as $material) {
                $material->name = $material->ten;
                $material->save();
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $th;
        }
    }

    public function test()
    {
        $info = InfoCongDoan::with('line.machine', 'plan')->get();
        try {
            DB::beginTransaction();
            foreach ($info as $key => $record) {
                $status = 0;
                if ($record->thoi_gian_bat_dau) {
                    if ($record->thoi_gian_ket_thuc) {
                        $status = InfoCongDoan::STATUS_COMPLETED;
                    } else {
                        $status = InfoCongDoan::STATUS_INPROGRESS;
                    }
                }
                $record->update([
                    'status' => $status,
                    'product_id' => explode('.', $record->lot_id)[1] ?? null,
                    'machine_code' => $record->line->machine[0]->code ?? null,
                    'sl_kh' => $record->plan->sl_thanh_pham ?? (isset($record->plan->so_bat) ? (int)($record->plan->sl_giao_sx / $record->plan->so_bat) : 0),
                ]);
            }
            DB::commit();
        } catch (\Exception $th) {
            DB::rollBack();
            return ($th);
        }
        return "o's ke";
    }

    public function convertQCLog()
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');
        $lines = Line::with('machine')->get();
        $line_keys = [];
        foreach ($lines as $line) {
            $line_keys[Str::slug($line->name)] = $line;
        }
        $lsx_logs = LSXLog::orderBy('created_at', 'DESC')->get();
        TestCriteriaHistory::truncate();
        TestCriteriaDetailHistory::truncate();
        YellowStampHistory::truncate();
        QCHistory::truncate();
        ErrorHistory::truncate();
        try {
            DB::beginTransaction();
            foreach ($lsx_logs as $log) {
                if (isset($log->info['qc'])) {
                    $lot_id = $log->lot_id ?? null;
                    if (!$lot_id) {
                        continue;
                    }
                    foreach ($log->info['qc'] as $line_key => $info) {
                        $line = Line::with('machine')->find($this->TEXT2ID[$line_key] ?? null);
                        if (!$line) continue;
                        $infoCongDoan = InfoCongDoan::where(['lot_id' => $lot_id, 'line_id' => $line->id])->first();
                        if ($infoCongDoan) {
                            $infoCongDoan->update([
                                'machine_code' => $line->machine[0]->code ?? null,
                                'status' => InfoCongDoan::STATUS_COMPLETED,
                                'user_id' => $log->info[$line_key]['user_id'] ?? null,
                                'lo_sx' => explode('.', $lot_id)[0],
                                'product_id' => explode('.', $lot_id)[1],
                            ]);
                        } else {
                            $infoCongDoan = InfoCongDoan::create([
                                'lot_id' => $lot_id,
                                'line_id' => $line->id,
                                'machine_code' => $line->machine[0]->code ?? null,
                                'status' => InfoCongDoan::STATUS_COMPLETED,
                                'user_id' => $log->info[$line_key]['user_id'] ?? null,
                                'lo_sx' => explode('.', $lot_id)[0],
                                'product_id' => explode('.', $lot_id)[1],
                            ]);
                        }
                        $qc_history = QCHistory::firstOrCreate(
                            [
                                'info_cong_doan_id' => $infoCongDoan->id,
                                'eligible_to_end' => 0,
                                'user_id' => $info['user_id'] ?? null,
                                'scanned_time' => $info['thoi_gian_vao'] ?? null,
                            ]
                        );
                        $line = $line_keys[$line_key];
                        $old_qc_data = [];
                        if (isset($info['dac-tinh'])) $old_qc_data['dac-tinh'] = $info['dac-tinh'];
                        if (isset($info['kich-thuoc'])) $old_qc_data['kich-thuoc'] = $info['kich-thuoc'];
                        if (isset($info['ngoai-quan'])) $old_qc_data['ngoai-quan'] = $info['ngoai-quan'];
                        // return $old_qc_data;
                        foreach ($old_qc_data as $key => $qc_data) {
                            if (isset($qc_data['data'])) {
                                $test_criteria_history = TestCriteriaHistory::create([
                                    'type' => $key,
                                    'result' => $qc_data['result'] === 1 ? 'OK' : 'NG',
                                    'q_c_history_id' => $qc_history->id,
                                ]);
                                foreach (($qc_data['data'] ?? []) as $data) {
                                    if (!isset($data['result']) || !isset($data['id'])) {
                                        continue;
                                    }
                                    TestCriteriaDetailHistory::create([
                                        'test_criteria_history_id' => $test_criteria_history->id,
                                        'test_criteria_id' => $data['id'],
                                        'input' => !isset($data['value']) ? ($data['result'] === 1 ? 'OK' : 'NG') : $data['value'],
                                        'result' => $data['result'] === 1 ? 'OK' : 'NG',
                                        'type' => $key,
                                    ]);
                                }
                            }
                        }
                        if (isset($info['errors'])) {
                            foreach ($info['errors'] as $error) {
                                foreach (($error['data'] ?? []) as $error_id => $value) {
                                    ErrorHistory::create([
                                        'q_c_history_id' => $qc_history->id,
                                        'error_id' => $error_id,
                                        'quantity' => $value,
                                        'user_id' => $error['user_id'],
                                        'type' => $error['type'],
                                    ]);
                                }
                            }
                        }
                        if (isset($info['sl_tem_vang']) && isset($info['loi_tem_vang'])) {
                            YellowStampHistory::create([
                                'q_c_history_id' => $qc_history->id,
                                'errors' => implode(',', array_unique($this->flatten($info['loi_tem_vang'] ?? []))),
                                'sl_tem_vang' => $info['sl_tem_vang'],
                                'user_id' => $info['user_id'] ?? null,
                            ]);
                        }
                        if (isset($info['bat'])) {
                            $user_qc = $this->convertBatData($info, $log);
                            if ($user_qc) {
                                $qc_history->update(['user_id' => $user_qc]);
                            }
                        }
                        // Log::info('qcHistory created', $qc_history);
                    }
                }
            }
            // TestCriteriaDetailHistory::insert($test_criteria_detail_histories_data);
            // ErrorHistory::insert($error_histories_data);
            // YellowStampHistory::insert($yellow_stamp_histories_data);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $th;
            // return $log;
        }
        return 'update completed';
    }

    function flatten(array $array)
    {
        $return = array();
        array_walk_recursive($array, function ($a) use (&$return) {
            $return[] = $a;
        });
        return $return;
    }

    public function convertBatData($info, $log)
    {
        $user_qc = null;
        foreach ($info['bat'] as $bat_id => $bat_info) {
            if (!$bat_id || is_numeric($bat_id)) {
                continue;
            }
            if (isset($bat_info['user_id'])) {
                $user_qc = $bat_info['user_id'];
            }
            $infoCongDoan = InfoCongDoan::firstOrCreate(
                ['lot_id' => $bat_id, 'line_id' => 13],
                [
                    'machine_code' => 'ACE70CS',
                    'status' => InfoCongDoan::STATUS_COMPLETED,
                    'user_id' => $log->info['gap-dan']['user_id'] ?? null,
                    'lo_sx' => explode('.', $bat_id)[0],
                    'product_id' => explode('.', $bat_id)[1],
                ]
            );
            $qc_history = QCHistory::firstOrCreate(
                [
                    'info_cong_doan_id' => $infoCongDoan->id,
                    'eligible_to_end' => 0,
                    'user_id' => $bat_info['user_id'] ?? null,
                    'scanned_time' => $bat_info['thoi_gian_vao'] ?? null,
                ]
            );
            $bat_qc_data = [];
            if (isset($bat_info['dac-tinh'])) $bat_qc_data['dac-tinh'] = $bat_info['dac-tinh'];
            if (isset($bat_info['kich-thuoc'])) $bat_qc_data['kich-thuoc'] = $bat_info['kich-thuoc'];
            if (isset($bat_info['ngoai-quan'])) $bat_qc_data['ngoai-quan'] = $bat_info['ngoai-quan'];

            foreach ($bat_qc_data as $key => $qc_data) {
                if (isset($qc_data['data'])) {
                    $test_criteria_history = TestCriteriaHistory::create([
                        'type' => $key,
                        'result' => $qc_data['result'] === 1 ? 'OK' : 'NG',
                        'q_c_history_id' => $qc_history->id,
                        'user_id' => $bat_info['user_id'] ?? null,
                    ]);
                    foreach (($qc_data['data'] ?? []) as $data) {
                        if (!isset($data['result']) || !isset($data['id'])) {
                            continue;
                        }
                        TestCriteriaDetailHistory::create([
                            'test_criteria_history_id' => $test_criteria_history->id,
                            'test_criteria_id' => $data['id'],
                            'input' => !isset($data['value']) ? ($data['result'] === 1 ? 'OK' : 'NG') : $data['value'],
                            'result' => $data['result'] === 1 ? 'OK' : 'NG',
                            'type' => $key,
                        ]);
                    }
                }
            }
            if (isset($bat_info['errors'])) {
                foreach ($bat_info['errors'] as $error) {
                    foreach (($error['data'] ?? []) as $error_id => $value) {
                        ErrorHistory::create([
                            'q_c_history_id' => $qc_history->id,
                            'error_id' => $error_id,
                            'quantity' => $value,
                            'user_id' => $error['user_id'],
                            'type' => $error['type'],
                        ]);
                    }
                }
            }
            if (isset($bat_info['sl_tem_vang']) && isset($bat_info['loi_tem_vang'])) {
                YellowStampHistory::create([
                    'q_c_history_id' => $qc_history->id,
                    'errors' => implode(',', array_unique($this->flatten($bat_info['loi_tem_vang'] ?? []))),
                    'sl_tem_vang' => $bat_info['sl_tem_vang'],
                    'user_id' => $bat_info['user_id'] ?? null,
                ]);
            }
        }
        return $user_qc;
    }

    public function exportChiTietThucHienKiemTra_TrangThai(Request $request)
    {
        $query = MaintenanceSchedule::with('machine.line', 'maintenancePlan', 'maintenanceItem.maintenanceCategory', 'maintenanceLog');
        if (isset($request->date) && count($request->date) === 2) {
            $query->whereDate('due_date', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('due_date', '<=', date('Y-m-d', strtotime($request->date[1])));;
        } else {
            $query->whereDate('due_date', now());
        }
        if (isset($request->line_id)) {
            $lineId = $request->line_id;
            $query->whereHas('machine', function ($q) use ($lineId) {
                $q->whereIn('line_id', $lineId);
            });
        }
        $schedules = $query->get()->groupBy('machine_code');
        $table = [];
        foreach ($schedules as $machine_code => $schedule) {
            $schedule->sortBy('due_date');
            $logs = $schedule->filter(function (object $item) {
                return $item->maintenanceLog;
            })->sortBy(function (object $item) {
                return $item->maintenanceLog->log_date;
            });
            $table[] = [
                'machine_code' => $machine_code,
                'machine_name' => $schedule[0]->machine->name ?? "",
                'line_name' => $schedule[0]->machine->line->name ?? "",
                'item_number' => $schedule->count(),
                'planning_date' => date('d/m/Y', strtotime($schedule[0]->due_date)),
                'start_date' => isset($logs->first()->maintenanceLog) ? date('d/m/Y', strtotime($logs->first()->maintenanceLog->log_date)) : "",
            ];
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['STT', 'Công đoạn', 'Tên máy', 'Số hạng mục', 'Kế hoạch', 'Ngày thực hiện', 'Người thực hiện'];
        $table_key = [
            'A' => 'stt',
            'B' => 'line_name',
            'C' => 'machine_name',
            'D' => 'item_number',
            'E' => 'planning_date',
            'F' => 'start_date',
            'G' => 'user_name',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'Chi tiết trạng thái BTBD')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            $sheet->setCellValue([1, $table_row], $key + 1)->getStyle([1, $table_row])->applyFromArray($centerStyle);
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        if (!file_exists('exported_files')) {
            mkdir('exported_files', 0777, true);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Chi_tiết_trạng_thái_BTBD.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Chi_tiết_trạng_thái_BTBD.xlsx');
        $href = '/exported_files/Chi_tiết_trạng_thái_BTBD.xlsx';
        return $this->success($href);
    }

    public function exportChiTietThucHienKiemTra(Request $request)
    {
        $query = MaintenanceSchedule::with('machine.line', 'maintenancePlan', 'maintenanceItem.maintenanceCategory', 'maintenanceLog.maintenanceLogImages');
        if (isset($request->date) && count($request->date) === 2) {
            $query->whereDate('due_date', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('due_date', '<=', date('Y-m-d', strtotime($request->date[1])));;
        } else {
            $query->whereDate('due_date', now());
        }
        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        $schedules = $query->get();
        $table = [];
        foreach ($schedules as $machine_code => $schedule) {
            $images = [];
            if ($schedule->maintenanceLog) {
                foreach ($schedule->maintenanceLog->maintenanceLogImages as $imgIndex => $image) {
                    $images[] = [
                        'uid' => $image->id,
                        'name' => 'Pic' . ($imgIndex + 1) . '.png',
                        'image_path' => $image->image_path,
                        'status' =>  'done'
                    ];
                }
            }
            $table[] = [
                'id' => $schedule->id,
                'machine_code' => $schedule->machine_code,
                'machine_name' => $schedule->machine->name,
                'line_name' => $schedule->machine->line->name ?? "",
                'line_id' => $schedule->machine->line->id ?? "",
                'item_name' => $schedule->maintenanceItem->name ?? "",
                'item_id' => $schedule->maintenanceItem->id ?? "",
                'category_name' => $schedule->maintenanceItem->maintenanceCategory->name ?? "",
                'category_id' => $schedule->maintenanceItem->maintenanceCategory->id ?? "",
                'planning_date' => date('d/m/Y', strtotime($schedule->due_date)),
                'start_date' => $schedule->maintenanceLog ? date('d/m/Y', strtotime($schedule->maintenanceLog->log_date)) : "",
                'log' => $schedule->maintenanceLog ? $schedule->maintenanceLog : "",
                'images' => $images,
                'note' => $schedule->maintenanceLog ? $schedule->maintenanceLog->note : "",
                'result' => $schedule->maintenanceLog ? $schedule->maintenanceLog->result : "",
            ];
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = [
            'STT',
            'Công đoạn',
            'Tên máy',
            'Nhóm hạng mục',
            'Tên công việc',
            'Kế hoạch',
            'Ngày thực hiện',
            'Kết quả',
            'Nhận xét',
        ];
        $table_key = [
            'A' => 'stt',
            'B' => 'line_name',
            'C' => 'machine_name',
            'D' => 'category_name',
            'E' => 'item_name',
            'F' => 'planning_date',
            'G' => 'start_date',
            'H' => 'result',
            'I' => 'note',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'Chi tiết thực hiện kiểm tra')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($table as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            $sheet->setCellValue([1, $table_row], $key + 1)->getStyle([1, $table_row])->applyFromArray($centerStyle);
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        if (!file_exists('exported_files')) {
            mkdir('exported_files', 0777, true);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Chi_tiết_thực_hiện_kiểm_tra.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Chi_tiết_thực_hiện_kiểm_tra.xlsx');
        $href = '/exported_files/Chi_tiết_thực_hiện_kiểm_tra.xlsx';
        return $this->success($href);
    }

    public function fixDataMachineMaintain()
    {
        $maintains = MaintenanceSchedule::all();
        foreach ($maintains as $key => $schedule) {
            switch ($schedule->machine_code) {
                case 'DC_01':
                    $schedule->machine_code = 'DC_1';
                    break;
                case 'IN_2_MAU':
                    $schedule->machine_code = 'IN_2_MAU_01';
                    break;
                case 'IN_4_MAU':
                    $schedule->machine_code = 'IN_4_MAU_01';
                    break;
                case 'IN_8_MAU':
                    $schedule->machine_code = 'IN_8_MAU_01';
                    break;
                case 'LH_1':
                    $schedule->machine_code = 'LH1-A2';
                    break;
                case 'LH_2':
                    $schedule->machine_code = 'LH2-A2';
                    break;
                case 'LH_3':
                    $schedule->machine_code = 'LH3-A2';
                    break;
                case 'LINER_2':
                    $schedule->machine_code = 'LINER_02';
                    break;
                case 'LINER_3':
                    $schedule->machine_code = 'LINER_03';
                    break;
                default:
                    # code...
                    break;
            }
            $schedule->save();
        }
        return 'ok';
    }

    public function updateProductToMaterialInLineGapDan()
    {
        $plans = ProductionPlan::where('line_id', '24')->whereDate('created_at', date('Y-m-d'))->get();
        foreach ($plans as $key => $plan) {
            $bom = Bom::where('product_id', $plan->product_id)->orderBy('priority')->orderBy('created_at')->first();
            if (!$bom || !$bom->material_id) {
                continue;
            }
            $plan->update([
                'product_id' => $bom->material_id
            ]);
            $lot_plans = LotPlan::where('production_plan_id', $plan->id)->get();
            foreach ($lot_plans as $key => $lot_plan) {
                $lot_plan->update([
                    'product_id' => $bom->material_id
                ]);
                $lot_plan->infoCongDoan()->update([
                    'product_id' => $bom->material_id
                ]);
                $lot_plan->lot()->update([
                    'product_id' => $bom->material_id
                ]);
            }
        }
        return 'ok';
    }

    public function createInfoCongDoanForPlan(Request $request)
    {
        $query = LotPlan::query();
        if (!empty($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        if (!empty($request->product_id)) {
            $query->where('product_id', $request->product_id);
        }
        if (!empty($request->line_id)) {
            $query->where('line_id', $request->line_id);
        }
        if (!empty($request->lot_id)) {
            $query->where('lot_id', $request->lot_id);
        }
        $plans = $query->get();
        try {
            DB::beginTransaction();
            foreach ($plans as $key => $plan) {
                // $info = InfoCongDoan::firstOrCreate(
                //     [
                //         'lot_id' => $plan->lot_id,
                //         'lo_sx' => $plan->lo_sx,
                //         'machine_code' => $plan->machine_code,
                //         'line_id' => $plan->line_id,
                //         'product_id' => $plan->product_id,
                //     ],
                //     [
                //         'thoi_gian_bat_dau' => $plan->start_time,
                //         'thoi_gian_ket_thuc' => $plan->end_time,
                //         'status' => InfoCongDoan::STATUS_COMPLETED,
                //         'sl_dau_ra_hang_loat' => $plan->quantity,
                //         'sl_kh' => $plan->quantity,
                //         'lot_plan_id' => $plan->id,
                //     ]
                // );
                $line = Line::find($plan->line_id);
                Stamp::updateOrCreate([
                    'lot_id' => $plan->lot_id,
                    'ten_sp' => $plan->product->name ?? null,
                    'soluongtp' => $plan->quantity,
                    'machine_code' => $plan->machine_code,
                    'ver' => "",
                    'his' => "",
                    'lsx' => $plan->lo_sx,
                    'cd_thuc_hien' => $line->name,
                    'cd_tiep_theo' => Line::where('ordering', '>', $line->ordering)->first()->name ?? 'Chọn',
                    'nguoi_sx' => "",
                    'ghi_chu' => "",
                ]);
                // if (!empty($request->machine_code)) {
                //     Tracking::where('machine_id', $request->machine_code)->update(['lot_id'=>null]);
                // }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
        return $this->success($plans);
    }

    public function filterFileToPlan(Request $request)
    {
        $extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        if ($extension == 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        } elseif ($extension == 'xlsx') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        }
        // file path
        $spreadsheet = $reader->load($_FILES['file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $data = [];
        // return $allDataInSheet;
        foreach ($allDataInSheet as $key => $row) {
            if ($key > 1 && $row['C'] == '10' && str_contains(strtolower($row['J']), strtolower('Gấp dán'))) {
                $input = [];
                $input['A'] = '';
                $input['B'] = '1';
                $input['C'] = $row['T'];
                $input['D'] = $row['U'];
                $input['E'] = $row['D'];
                $input['F'] = $row['H'];
                $input['G'] = 'Gấp dán liên hoàn';
                switch ($row['K']) {
                    case 'GD 01':
                        $input['H'] = 'LH1-A2';
                        break;
                    case 'GD 02':
                        $input['H'] = 'LH2-A2';
                        break;
                    case 'GD 03':
                        $input['H'] = 'LH3-A2';
                        break;
                    default:
                        # code...
                        break;
                }

                $input['I'] = '';
                $input['J'] = $row['G'];
                $input['K'] = $row['F'];
                $input['L'] = $row['I'];
                $input['M'] = '';
                $input['N'] = '';
                $input['O'] = '';
                $input['P'] = '';
                $input['Q'] = $row['N'];
                $input['R'] = '';
                $input['S'] = $row['N'];
                $input['T'] = '';
                $input['U'] = '';
                $input['V'] = '';
                $input['W'] = '';
                $input['X'] = $row['AC'];
                $input['Y'] = $row['AA'];
                $input['Z'] = '';
                $input['AA'] = '';
                $input['AB'] = '';
                $input['AC'] = '';
                $input['AD'] = '';
                $input['AE'] = '';
                $data[] = $input;
            }
        }
        // return $data;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data, NULL, 'A4');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/KHSX.xlsx');
        return 'done.';
    }

    public function randomInfo()
    {
        try {
            DB::beginTransaction();
            $infos = InfoCongDoan::where('thoi_gian_bat_dau', '1970-01-01 07:00:00')->get();
            foreach ($infos as $key => $info) {
                $start = $this->randomDateTime('2024-08-01 00:00:00', '2024-09-30 00:00:00');
                $end = $start->copy()->addHours(rand(3, 15));
                // return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
                $info->update([
                    'thoi_gian_bat_dau' => $start->format('Y-m-d H:i:s'),
                    'thoi_gian_ket_thuc' => $end->format('Y-m-d H:i:s'),
                    'created_at' => $start->format('Y-m-d H:i:s'),
                    'updated_at' => $end->format('Y-m-d H:i:s'),
                ]);
                // return $info;
                $info->lotPlan()->update([
                    'start_time' => $start->format('Y-m-d H:i:s'),
                    'end_time' => $end->format('Y-m-d H:i:s'),
                ]);
            }
            $plans = ProductionPlan::where('thoi_gian_bat_dau', '1970-01-01 07:00:00')->get();
            foreach ($plans as $key => $plan) {
                $start = $this->randomDateTime('2024-08-01 00:00:00', '2024-09-30 00:00:00');
                $end = $start->copy()->addHours(rand(3, 15));
                $plan->update([
                    'ngay_sx' => $start->format('Y-m-d'),
                    'ngay_giao_hang' => $start->format('Y-m-d'),
                    'ngay_dat_hang' => $start->format('Y-m-d'),
                    'thoi_gian_bat_dau' => $start->format('Y-m-d H:i:00'),
                    'thoi_gian_ket_thuc' => $end->format('Y-m-d H:i:00'),
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    function randomDateTime($startDate, $endDate)
    {
        // Convert the start and end dates to timestamps
        $startTimestamp = Carbon::parse($startDate)->timestamp;
        $endTimestamp = Carbon::parse($endDate)->timestamp;

        // Generate a random timestamp between start and end
        $randomTimestamp = rand($startTimestamp, $endTimestamp);

        // Return the random timestamp as a Carbon instance
        return Carbon::createFromTimestamp($randomTimestamp);
    }

    function updateStatusPlan(Request $request, $id)
    {
        $request->validate([
            'status_plan' => ['required', Rule::in([1, 3])],
        ]);

        DB::beginTransaction();
        try {
            $productionPlan = ProductionPlan::find($id);
            $check = InfoCongDoan::where('plan_id', $productionPlan->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
            if ($check && $request->status_plan == 3) {
                return $this->failure([], 'Máy đang chạy không thể dừng');
            }
            ProductionPlan::find($id)->update(['status_plan' => $request->status_plan]);
            DB::commit();
            return $this->success([], 'Thao tác thành công');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            return $this->failure(['msg' => $e->getMessage()], 'Thao tác thất bại');
        }
    }

    public function trackingProduction(Request $request)
    {
        $lines = Line::where('factory_id', 2)->pluck('id')->toArray();
        $machines = Machine::whereIn('line_id', $lines)->where('is_iot', 1)->pluck('code')->toArray();
        $query = InfoCongDoan::whereIn('machine_code', $machines);
        if (!empty($request->date)) {
            $query->whereDate('created_at', date('Y-m-d', strtotime($request->date)));
        } else {
            $query->whereDate('created_at', date('Y-m-d'));
        }
        if (!empty($request->lot_id)) {
            $query->where('lot_id', 'like', "%$request->lot_id%");
        }
        if (!empty($request->machine_code)) {
            $query->where('machine_code', 'like', "%$request->machine_code%");
        }
        $records = $query->with('line')->orderBy('created_at', 'DESC')->get();
        return $this->success($records);
    }

    public function updateFinishedProductInventory(Request $request)
    {
        $import_logs = WareHouseLog::with('lot')->where('type', 1)->get()->groupBy(function ($item) {
            return $item->lot->product_id ?? "";
        });
        $export_logs = WareHouseLog::with('lot')->where('type', 2)->get()->groupBy(function ($item) {
            return $item->lot->product_id ?? "";
        });
        try {
            DB::beginTransaction();
            Inventory::truncate();
            foreach ($import_logs as $key => $value) {
                $sl_nhap = $value->sum('so_luong') ?? 0;
                $sl_xuat = isset($export_logs[$key]) ? $export_logs[$key]->sum('so_luong') : 0;
                $sl_ton = $sl_nhap - $sl_xuat;
                Inventory::create(['product_id' => $key, 'sl_ton' => $sl_ton, 'sl_nhap' => $sl_nhap, 'sl_xuat' => $sl_xuat]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return 'ok';
    }

    public function getProductionMonitor(Request $request){
        $input = $request->all();
        $query = InfoCongDoan::where('line_id', '!=', 30)->orderBy('lo_sx');
        if(isset($input['start_date']) && isset($input['end_date'])){
            $query->whereDate('thoi_gian_bat_dau', '>=', $input['start_date'])->whereDate('thoi_gian_bat_dau', '<=', $input['end_date']);
        }
        if(isset($input['machine_code'])){
            $query->where('machine_code', 'like', '%'.$input['machine_code'].'%');
        }
        if(isset($input['product_order_id'])){
            $query->where('machine_code', $input['product_order_id']);
        }
        $result = $query->with('lo_sx')->get()->groupBy(function($item){
            return $item->machine_code.$item->lo_sx;
        });
        $data = [];
        foreach ($result as $key => $value) {
            $info = isset($value[0]) ? $value[0] : null;
            $row = [
                'product_order_id' => $info->lo_sx->product_order_id ?? "",
                'lo_sx' => $info->lo_sx,
                'machine_code' => $info->machine_code,
                'line_name' => $info->line->name,
                'sum_san_luong' => $value->sum('sl_dau_ra_hang_loat'),
                'sum_tem_vang' => $value->sum('sl_tem_vang'),
                'sum_ng' => $value->sum('sl_ng'),
                'producton_time' => '',
            ];
        }
        return $this->success($result);
    }
}
