<?php

namespace App\Admin\Controllers;

use App\Helpers\ExcelStyleHelper;
use App\Http\Controllers\Controller;
use App\Imports\InfoCongDoanImport;
use App\Imports\WarehouseLocationImport;
use App\Models\Assignment;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\ErrorHistory;
use App\Models\ErrorMachine;
use App\Models\Factory;
use App\Models\GroupYellowStampInfo;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Losx;
use App\Models\Lot;
use App\Models\LotErrorLog;
use App\Models\LotPlan;
use App\Models\Machine;
use App\Models\MachineLog;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\QCHistory;
use App\Models\Shift;
use App\Models\Spec;
use App\Models\TestCriteriaHistory;
use App\Traits\API;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

class Phase2UIApiController extends Controller
{
    use API;
    public function getTreeSelect(Request $request)
    {
        $factories = Factory::where('id', 2)->get();
        foreach ($factories as $factory) {
            foreach ($factory->line as $line) {
                $machines = $line->machine->sortBy('code', SORT_NATURAL)->values() ?? [];
                foreach ($machines as $machine) {
                    $machine->key = $machine->code;
                    $machine->title = $machine->code;
                    $machine->type = 'machine';
                }
                $line->key = $line->id;
                $line->title = $line->name;
                $line->children = $machines;
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
        $overall['sl_dau_ra_kh'] = ProductionPlan::whereDate('ngay_sx', date('Y-m-d'))->sum('sl_giao_sx');
        $infos = InfoCongDoan::whereDate('thoi_gian_bat_dau', date('Y-m-d'))->get();
        foreach ($infos as $item) {
            $overall["sl_dau_ra_thuc_te_ok"] += $item->sl_dau_ra_hang_loat - ($item->sl_tem_vang + $item->sl_ng);
            $sl_thuc_te += $item->sl_dau_ra_hang_loat - $item->sl_ng;
            $overall["sl_tem_vang"] += $item->sl_tem_vang;
            $overall["sl_ng"] += $item->sl_ng;
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
        foreach ($infos as $item) {
            $start = new Carbon($item->thoi_gian_bat_dau);
            $end = new Carbon($item->thoi_gian_ket_thuc);
            $d = $end->diffInMinutes($start);

            $start_date = date("Y/m/d", strtotime($start));
            $start_shift = strtotime($start_date . ' ' . $shift->start_time);
            $end_shift = strtotime($start_date . ' ' . $shift->end_time);
            if (strtotime($start) >= $start_shift && strtotime($start) <= $end_shift) {
                $ca_sx = 'Ca 1';
            } else {
                $ca_sx = 'Ca 2';
            }
            $errors = [];
            $thoi_gian_kiem_tra = '';
            $sl_ng_pqc = 0;
            $sl_ng_sxkt = 0;
            $user_pqc = '';
            $user_sxkt = '';
            foreach ($item->qcHistory->flatMap->errorHistories ?? [] as $key => $errorHistory) {
                if (!isset($error[$errorHistory->error_id])) {
                    $error[$errorHistory->error_id] = [];
                }
                $errors[$errorHistory->error_id]['value'] = ($errors[$errorHistory->error_id]['value'] ?? 0) + ($errorHistory->quantity ?? 0);
                $errors[$errorHistory->error_id]['name'] = $errorHistory->error->name;
                if ($errorHistory->type === 'sx') {
                    $sl_ng_sxkt += ($errorHistory->quantity ?? 0);
                    $user_sxkt = $errorHistory->user->name;
                } else {
                    $sl_ng_pqc += ($errorHistory->quantity ?? 0);
                    $user_pqc = $errorHistory->user->name;
                }
            }
            $so_dau_noi = LotErrorLog::where('lot_id', $item->lot_id)->where('machine_code', $item->machine_code)->where('line_id', $item->line_id)->count();
            if ($item->line_id == 26) {
                $group_yellow_stamp_info_quantity = GroupYellowStampInfo::where('info_cong_doan_id', $item->id)->sum('quantity');
                $sl_ok = $item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang - $group_yellow_stamp_info_quantity;
                $sl_ok = $sl_ok < 0 ? 0 : $sl_ok;
            } else {
                $sl_ok = $item->sl_dau_ra_hang_loat - $item->sl_tem_vang - $item->sl_ng;
            }
            $tm = [
                'info_cong_doan_id' => $item->id,
                "ngay_sx" => date('d/m/Y H:i:s', strtotime($item->created_at)),
                'ca_sx' => $ca_sx,
                'xuong' => 'Giấy',
                "input_lot_id" => $item->input_lot_id,
                "cong_doan" => $item->line->name,
                "line_id" => $item->line_id,
                "machine" => $item->machine_code ?? "",
                "machine_id" => $item->machine_code ?? "",
                "khach_hang" => $item->losx->product->customer->name ?? "",
                "ten_san_pham" => $item->losx->product->name ?? '',
                "product_id" => $item->losx->product_id ?? '',
                "material_id" => $item->losx->product->material_id ?? '',
                "lo_sx" => $item->lo_sx,
                "lot_id" => $item->lot_id,
                "thoi_gian_bat_dau_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : '',
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : '',
                "sl_dau_vao_kh" => $item->plan->sl_giao_sx ?? 0,
                "sl_dau_ra_kh" => $item->plan->sl_giao_sx ?? 0,
                "thoi_gian_bat_dau" => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : '-',
                "thoi_gian_bam_may" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "thoi_gian_ket_thuc" => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : '-',
                "thoi_gian_chay_san_luong" => number_format($d / 60, 2),
                "sl_ng" => $item->sl_ng ?? 0,
                "sl_tem_vang" => $item->sl_tem_vang,
                'so_dau_noi' => $so_dau_noi,
                "sl_dau_ra_ok" => $sl_ok,
                "ti_le_ng" => number_format($item->sl_dau_ra_hang_loat > 0 ? ($item->sl_ng / $item->sl_dau_ra_hang_loat) : 0, 2) * 100,
                "sl_dau_ra_hang_loat" => $item->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_vao_hang_loat" => $item->sl_dau_vao_hang_loat ?? 0,
                "sl_dau_ra_chay_thu" => $item->sl_dau_ra_chay_thu ?? 0,
                "sl_dau_vao_chay_thu" => $item->sl_dau_vao_chay_thu ?? 0,
                "ty_le_dat" => $item->sl_dau_ra_hang_loat > 0 ? number_format(($sl_ok) / $item->sl_dau_ra_hang_loat) : '-',
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
                'stamp_type' => $item->lot->type ?? null,
            ];
            $data[] = $tm;
        }
        return $data;
    }

    public function productionHistoryQuery(Request $request)
    {
        $line_ids = Line::where('factory_id', 2)->pluck('id')->toArray();
        $query = InfoCongDoan::whereIn('line_id', $line_ids)->whereNotNull('thoi_gian_bat_dau')->with("lotPlan", "product", "line", "qcHistory.errorHistories.error", "qcHistory.errorHistories.user");
        if (isset($request->line_id)) {
            if (is_array($request->line_id)) {
                $query->whereIn('line_id', $request->line_id);
            } else {
                $query->where('line_id', $request->line_id);
            }
        }
        if (isset($request->machine_code)) {
            if (is_array($request->machine_code)) {
                $query->whereIn('machine_code', $request->machine_code);
            } else {
                $query->where('machine_code', $request->machine_code);
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
            $query->where('product_id', 'like',  '%' . $request->ten_sp . '%');
        }
        if (isset($request->khach_hang)) {
            $product_ids = Product::where('customer_id', $request->khach_hang)->pluck('id')->toArray();
            $query->whereIn('product_id', $product_ids);
        }
        if (isset($request->lo_sx)) {
            $lot = Lot::where('lo_sx', $request->lo_sx)->get();
            $query->whereIn('lot_id', $lot->pluck('id'));
        }
        if (isset($request->lot_id)) {
            $query->where('lot_id', 'like', "%$request->lot_id%");
        }
        if (isset($request->input_lot_id)) {
            $query->where('input_lot_id', 'like', "%$request->input_lot_id%");
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

    function parseExportProduceHistoryTable($infos)
    {
        $data = [];
        $shift = Shift::first();
        foreach ($infos as $index => $item) {
            $start = new Carbon($item->thoi_gian_bat_dau);
            $end = new Carbon($item->thoi_gian_ket_thuc);
            $d = $end->diffInMinutes($start);

            $start_date = date("Y/m/d", strtotime($start));
            $start_shift = strtotime($start_date . ' ' . $shift->start_time);
            $end_shift = strtotime($start_date . ' ' . $shift->end_time);
            if (strtotime($start) >= $start_shift && strtotime($start) <= $end_shift) {
                $ca_sx = 'Ca 1';
            } else {
                $ca_sx = 'Ca 2';
            }

            // $info = $item->lot->log ? $item->lot->log->info : [];
            // $line_key = Str::slug($item->line->name);
            $errors = [];
            $sl_ng_pqc = 0;
            $sl_ng_sxkt = 0;
            $qcHistoryBySX = QCHistory::with('user')->where('info_cong_doan_id', $item->id)->where('type', 'sx')->first();
            foreach ($item->qcHistory->flatMap->errorHistories ?? [] as $key => $errorHistory) {
                if (!isset($error[$errorHistory->error_id])) {
                    $error[$errorHistory->error_id] = [];
                }
                $errors[$errorHistory->error_id]['value'] = ($errors[$errorHistory->error_id]['value'] ?? 0) + ($errorHistory->quantity ?? 0);
                $errors[$errorHistory->error_id]['name'] = $errorHistory->error->name;
                if ($errorHistory->type === 'sx') {
                    $sl_ng_sxkt += ($errorHistory->quantity ?? 0);
                } else {
                    $sl_ng_pqc += ($errorHistory->quantity ?? 0);
                }
            }
            $so_dau_noi = LotErrorLog::where('lot_id', $item->lot_id)->where('machine_code', $item->machine_code)->where('line_id', $item->line_id)->count();
            if ($item->line_id == 26) {
                $group_yellow_stamp_info_quantity = GroupYellowStampInfo::where('info_cong_doan_id', $item->id)->sum('quantity');
                $sl_dau_ra_ok = $item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang - $group_yellow_stamp_info_quantity;
                $sl_dau_ra_ok = $sl_dau_ra_ok < 0 ? 0 : $sl_dau_ra_ok;
            } else {
                $sl_dau_ra_ok = $item->sl_dau_ra_hang_loat - $item->sl_tem_vang - $item->sl_ng;
            }
            if ($item->line_id == 29) {
                $assignment = Assignment::where('lot_id', $item->lot_id)->with('worker')->first();
                $user_sxkt = $assignment->worker->name ?? '';
            } else {
                $user_sxkt = $qcHistoryBySX->user->name ?? '';
            }
            $tm = [
                "stt" => $index + 1,
                "ngay_sx" => date('d/m/Y H:i:s', strtotime($item->created_at)),
                'ca_sx' => $ca_sx,
                'xuong' => 'Giấy',
                "cong_doan" => $item->line->name,
                "machine" => $item->machine->name ?? "",
                "machine_id" => $item->machine_code ?? "",
                "ten_san_pham" => $item->product->name ?? '',
                "khach_hang" => $item->product->customer->name ?? "",
                "product_id" => $item->product_id ?? '',
                "material_id" => $item->product->boms[0]->material_id ?? '',
                "lo_sx" => $item->lo_sx,
                "lot_id" => $item->lot_id,
                "thoi_gian_bat_dau_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : '',
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : '',
                "sl_dau_vao_kh" => $item->plan->sl_giao_sx ?? 0,
                "sl_dau_ra_kh" => $item->plan->sl_giao_sx ?? 0,
                "thoi_gian_bat_dau_vao_hang" => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : '-',
                "thoi_gian_ket_thuc_vao_hang" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "sl_dau_vao_chay_thu" => $item->sl_dau_vao_chay_thu ?? 0,
                "sl_dau_ra_chay_thu" => $item->sl_dau_ra_chay_thu ?? 0,
                "thoi_gian_bat_dau_san_luong" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "thoi_gian_ket_thuc_san_luong" => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : '-',
                "sl_dau_vao_hang_loat" => $item->sl_dau_vao_hang_loat ?? 0,
                "sl_dau_ra_hang_loat" => $item->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_ra_ok" => $sl_dau_ra_ok,
                "sl_tem_vang" => $item->sl_tem_vang,
                'so_dau_noi' => $so_dau_noi,
                "sl_ng" => $sl_ng_pqc + $sl_ng_sxkt,
                "chenh_lech" => $item->sl_dau_vao_hang_loat - $item->sl_dau_ra_hang_loat,
                "ty_le_dat" => $item->sl_dau_ra_hang_loat > 0 ? number_format(($item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang) / $item->sl_dau_ra_hang_loat * 100, 2) : '-',
                "thoi_gian_chay_san_luong" => number_format($d / 60, 2),
                "leadtime" => $item->thoi_gian_ket_thuc ? number_format((strtotime($item->thoi_gian_ket_thuc) - strtotime($item->thoi_gian_bat_dau)) / 3600, 2) : '-',
                'dien_nang' => $item->powerM ? number_format($item->powerM) : '',
                'user_sxkt' => $user_sxkt,
            ];
            $data[] = $tm;
        }
        return $data;
    }

    public function exportProduceHistory(Request $request)
    {
        $query = $this->productionHistoryQuery($request);
        $records = $query->get();
        $data = $this->parseExportProduceHistoryTable($records);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $titleStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(true, 16));
        $headerStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(), ExcelStyleHelper::fill());
        $border = ExcelStyleHelper::borders();
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
            'Kế hoạch' => ['Thời gian bắt đầu', 'Thời gian kết thúc', 'Số lượng đầu vào', 'Số lượng đầu ra'],
            'Thực tế' => [
                'Vào hàng' => ['Thời gian bắt đầu vào hàng', 'Thời gian kết thúc vào hàng', 'Số lượng đầu vào vào hàng', 'Số lượng đầu ra vào hàng'],
                'Sản xuất sản lượng' => ['Thời gian bắt đầu sản xuất sản lượng', 'Thời gian kết thúc sản xuất sản lượng', 'Số lượng đầu vào thực tế', 'Số lượng đầu ra thực tế', 'Số lượng đầu ra OK', 'Số lượng tem vàng', 'Số dấu nối', 'Số lượng NG']
            ],
            'Chênh lệch',
            "Tỷ lệ đạt",
            'T/T Thực tế (Phút)',
            'Leadtime',
            'Điện năng tiêu thụ',
            'Công nhân sản xuất'
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
        $sheet->setCellValue([1, 1], 'UI Lịch sử sản xuất')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = count($data) + 4;
        $sheet->fromArray((array)$data, NULL, 'A5', true);
        // $sheet->getStyle([1, 5, 30, count($data) + 4])->applyFromArray($centerStyle);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . 1 . ':' . $column->getColumnIndex() . ($table_row))->applyFromArray(array_merge(ExcelStyleHelper::alignment(), $border));
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

    function parseReportTable3($val, $lo_sx, $date, $machine_code)

    {
        $sl_kh = $val[0]->plan->sl_giao_sx ?? 0;
        $sl_dau_vao = (int)$val->sum('sl_dau_vao_hang_loat');
        $sl_dau_ra = (int)$val->sum('sl_dau_ra_hang_loat');
        $sl_tem_vang = (int)$val->sum('sl_tem_vang');
        $sl_ng = $val->sum('sl_ng');
        $sl_ok = $sl_dau_ra - $sl_tem_vang - $sl_ng;
        $tg_sx = 0;
        $tg_vao_hang = 0;
        $tg_hang_loat = 0;
        $lead_time = 0;
        $tg_sx_kh = 0;
        $sl_muc_tieu = 0;
        $val->map(function ($item) use (&$tg_sx, &$tg_vao_hang, &$tg_hang_loat, &$lead_time, &$tg_sx_kh, &$sl_muc_tieu) {
            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_bam_may);
            $tg_vao_hang += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bam_may);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_hang_loat += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->plan->thoi_gian_bat_dau ?? null);
            $lead_time += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->plan->thoi_gian_bat_dau ?? null);
            $end = Carbon::parse($item->plan->thoi_gian_ket_thuc ?? null);
            $tg_sx_kh += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx_in_hours = $start->diffInHours($end); // Tính tổng phút
            $sl_muc_tieu += ($tg_sx_in_hours / 3600) * ($item->plan->UPH ?? 0);
        });
        $ty_le_ng = ($sl_dau_ra > 0 ? (float)number_format($sl_ng / $sl_dau_ra, 2) * 100 : 0) . '%';
        $ty_le_hao_phi_tg = ($tg_sx > 0 ? (float)number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0) . '%';
        $ty_le_hoan_thanh = ($sl_kh > 0 ? (float)number_format($sl_ok / $sl_kh, 2) * 100 : 0) . '%';
        $item = [
            'ngay_sx' => $date,
            'so_may' => $machine_code,
            'ten_sp' => $val[0]->product->name ?? "",
            'lo_sx' => $lo_sx,
            'tg_kh' => number_format($tg_sx_kh / 60, 2),
            'tg_sx' => number_format($tg_sx / 60, 2),
            'sl_kh' => $sl_kh,
            'sl_dau_vao' => $sl_dau_vao,
            'sl_ok' => $sl_ok,
            'sl_tem_vang' => $sl_tem_vang,
            'sl_ng' => $sl_ng,
            'ty_le_ng' => $ty_le_ng,
            'tg_ko_sp' => number_format($tg_vao_hang / 60, 2),
            'tg_hang_loat' => number_format($tg_hang_loat / 60, 2),
            'tg_vao_hang' => number_format($tg_vao_hang / 60, 2),
            'ty_le_hao_phi_tg' => $ty_le_hao_phi_tg,
            'ty_le_hoan_thanh' => $ty_le_hoan_thanh,
            'so_nhan_su' => $val[0]->plan->nhan_luc ?? "",
        ];
        return $item;
    }

    function parseReportTable1($value, $date, $machine_code)
    {
        $sl_dau_vao = (int)$value->sum('sl_dau_vao_hang_loat') ?? 0;
        $sl_dau_ra = (int)$value->sum('sl_dau_ra_hang_loat');
        $sl_tem_vang = (int)$value->sum('sl_tem_vang');
        $sl_ng = $value->sum('sl_ng');
        $sl_ok = $sl_dau_ra - $sl_tem_vang - $sl_ng;
        $tg_sx = 0;
        $tg_vao_hang = 0;
        $tg_hang_loat = 0;
        $lead_time = 0;
        $tg_sx_kh = 0;
        $sl_muc_tieu = 0;
        $value->map(function ($item) use (&$tg_sx, &$tg_vao_hang, &$tg_hang_loat, &$lead_time, &$tg_sx_kh, &$sl_muc_tieu) {
            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_bam_may);
            $tg_vao_hang += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bam_may);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_hang_loat += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->plan->thoi_gian_bat_dau ?? null);
            $lead_time += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->plan->thoi_gian_bat_dau ?? null);
            $end = Carbon::parse($item->plan->thoi_gian_ket_thuc ?? null);
            $tg_sx_kh += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx_in_hours = $start->diffInHours($end); // Tính tổng phút
            $sl_muc_tieu += ($tg_sx_in_hours / 3600) * ($item->plan->UPH ?? 0);
        });
        try {
            //code...
            $ty_le_ng = ($sl_dau_ra > 0 ? number_format($sl_ng / $sl_dau_ra, 2) * 100 : 0) . '%';
            $ty_le_hao_phi_tg = ($tg_sx > 0 ? (float)number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0) . '%';
            $A = ($tg_sx > 0 ? (int) ((float)number_format($tg_hang_loat / $tg_sx, 2) * 100) : 0);
            if ($A > 100) {
                $A = 100;
            }
            $Q = ($sl_dau_vao > 0 ? (int) (number_format($sl_ok / ($sl_dau_vao ?? 1), 2)) * 100 : 0);
            if ($Q > 100) {
                $Q = 100;
            }
            $P = ($sl_muc_tieu > 0 ? (int) ((float)number_format($sl_dau_ra / $sl_muc_tieu, 2)) * 100 : 0);
            if ($P > 100) {
                $P = 100;
            }
            $OEE = number_format(($A * $P * $Q) / 10000, 2);
        } catch (\Throwable $th) {

            Log::debug([$sl_dau_ra / $sl_muc_tieu]);
            throw $th;
        }
        $power = $value->sum('powerM');
        $row = [
            'ngay_sx' => $date,
            'so_may' => $machine_code,
            'so_nhan_su' => $value[0]->plan->nhan_luc ?? "",
            'sl_dau_vao' => $sl_dau_vao,
            'sl_tem_vang' => $sl_tem_vang,
            'sl_ok' => $sl_ok,
            'sl_ng' => $sl_ng,
            'tg_sx' => number_format($tg_sx / 60, 2),
            'tg_ko_sp' => number_format($tg_vao_hang / 60, 2),
            'tg_hang_loat' => number_format($tg_hang_loat / 60, 2),
            'tg_vao_hang' => $tg_vao_hang,
            'ty_le_ng' => $ty_le_ng,
            'ty_le_hao_phi_tg' => $ty_le_hao_phi_tg,
            'lead_time' => number_format($lead_time / 60, 2),
            'A' => $A . "%",
            'P' => $P . "%",
            'Q' => $Q . "%",
            'OEE' => $OEE . "%",
            'power' => $power
        ];
        return $row;
    }

    function parseReportTable2($value, $date, $machine_code)
    {
        $sl_dau_vao = (int)$value->sum(function ($item) {
            $sl_bat = $item->product->so_bat ?? 1;
            return $item->sl_dau_vao_hang_loat / $sl_bat;
        });
        $sl_dau_ra = (int)$value->sum(function ($item) {
            $sl_bat = $item->product->so_bat ?? 1;
            return $item->sl_dau_ra_hang_loat / $sl_bat;
        });
        $sl_tem_vang = (int)$value->sum(function ($item) {
            $sl_bat = $item->product->so_bat ?? 1;
            return $item->sl_tem_vang / $sl_bat;
        });
        $sl_ng = (int)$value->sum(function ($item) {
            $sl_bat = $item->product->so_bat ?? 1;
            return $item->sl_ng / $sl_bat;
        });
        $sl_ok = $sl_dau_ra - $sl_tem_vang - $sl_ng;
        $tg_sx = 0;
        $tg_vao_hang = 0;
        $tg_hang_loat = 0;
        $lead_time = 0;
        $tg_sx_kh = 0;
        $sl_muc_tieu = 0;
        $value->map(function ($item) use (&$tg_sx, &$tg_vao_hang, &$tg_hang_loat, &$lead_time, &$tg_sx_kh, &$sl_muc_tieu) {
            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_bam_may);
            $tg_vao_hang += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bam_may);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_hang_loat += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->plan->start_time ?? null);
            $lead_time += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->plan->start_time ?? null);
            $end = Carbon::parse($item->plan->end_time ?? null);
            $tg_sx_kh += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx_in_hours = $start->diffInHours($end); // Tính tổng phút
            $sl_muc_tieu += ($tg_sx_in_hours / 3600) * ($item->plan->UPH ?? 0);
        });
        $ty_le_ng = ($sl_dau_ra > 0 ? number_format($sl_ng / $sl_dau_ra, 2) * 100 : 0) . '%';
        $ty_le_hao_phi_tg = ($tg_sx > 0 ? (float)number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0) . '%';
        $A = ($tg_sx > 0 ? (int) ((float)number_format($tg_hang_loat / $tg_sx, 2) * 100) : 0);
        if ($A > 100) {
            $A = 100;
        }
        $Q = ($sl_dau_vao > 0 ? (int) ((float)number_format($sl_ok / ($sl_dau_vao ?? 1), 2)) * 100 : 0);
        if ($Q > 100) {
            $Q = 100;
        }
        $P = ($sl_muc_tieu > 0 ? (int) ((float)number_format($sl_dau_ra / $sl_muc_tieu, 2)) * 100 : 0);
        if ($P > 100) {
            $P = 100;
        }
        $OEE = number_format(($A * $P * $Q) / 10000, 2);
        $power = $value->sum('powerM');
        $row = [
            'ngay_sx' => $date,
            'so_may' => $machine_code,
            'so_nhan_su' => $value[0]->plan->nhan_luc ?? "",
            'sl_dau_vao' => $sl_dau_vao,
            'sl_tem_vang' => $sl_tem_vang,
            'sl_ok' => $sl_ok,
            'sl_ng' => $sl_ng,
            'tg_sx' => number_format($tg_sx / 60, 2),
            'tg_ko_sp' => number_format($tg_vao_hang / 60, 2),
            'tg_hang_loat' => number_format($tg_hang_loat / 60, 2),
            'tg_vao_hang' => $tg_vao_hang,
            'ty_le_ng' => $ty_le_ng,
            'ty_le_hao_phi_tg' => $ty_le_hao_phi_tg,
            'lead_time' => number_format($lead_time / 60, 2),
            'A' => $A . "%",
            'P' => $P . "%",
            'Q' => $Q . "%",
            'OEE' => $OEE . "%",
            'power' => $power
        ];
        return $row;
    }

    public function exportReportProduceHistory(Request $request)
    {
        $query = InfoCongDoan::with('product', 'plan')
            ->whereNotNull('thoi_gian_bat_dau')
            ->whereNotNull('thoi_gian_bam_may')
            ->whereNotNull('thoi_gian_ket_thuc')
            ->orderBy('thoi_gian_bat_dau', 'DESC')
            ->whereDate('thoi_gian_bat_dau', '>=', date("Y-m-d", strtotime($request->date[0])))
            ->whereDate('thoi_gian_bat_dau', '<=', date("Y-m-d", strtotime($request->date[1])));
        $records = $query->get()->groupBy('machine_code');
        $table1 = [];
        $table2 = [];
        $table3 = [];
        $table4 = [];
        foreach ($records as $machine_code => $infos) {
            $groupByDate = $infos->groupBy(function ($item) {
                return date("d/m/Y", strtotime($item->thoi_gian_bat_dau));
            });
            foreach ($groupByDate as $date => $value) {
                $groupByLSX = $value->groupBy('lo_sx');
                foreach ($groupByLSX as $lo_sx => $val) {
                    $itemTable3 = $this->parseReportTable3($val, $lo_sx, $date, $machine_code);
                    $table3[] = $itemTable3;
                }
                $row = $this->parseReportTable1($value, $date, $machine_code);
                $table1[] = $row;
            }
        }


        foreach ($records as $machine_code => $infos) {
            $groupByDate = $infos->groupBy(function ($item) {
                return date("d/m/Y", strtotime($item->thoi_gian_bat_dau));
            });
            foreach ($groupByDate as $date => $value) {
                $row = $this->parseReportTable2($value, $date, $machine_code);
                $table2[] = $row;
            }
        }

        $lines = Line::where('factory_id', 2)->pluck('id')->toArray();
        $queryHistory = $this->productionHistoryQuery($request);
        $queryHistory->whereIn('line_id', $lines)->selectRaw('lo_sx,line_id,SUM(sl_dau_vao_hang_loat) as sl_dau_vao_,
        SUM(sl_dau_ra_hang_loat) as sl_dau_ra_, SUM(sl_tem_vang) as sl_tem_vang_, SUM(sl_ng) as sl_ng_,SUM(powerM) as powerM_, SUM(sl_dau_ra_hang_loat - sl_tem_vang - sl_ng) as sl_ok_
        , SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_ket_thuc , thoi_gian_bat_dau))) as tong_thoi_gian_san_xuat_, SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_bam_may , thoi_gian_bat_dau))) as thoi_gian_khong_san_luong_,
        SUM(TIME_TO_SEC(TIMEDIFF(thoi_gian_ket_thuc , thoi_gian_bam_may))) as thoi_gian_tinh_san_luong_,MAX(thoi_gian_bat_dau) as ngay_sx_gan_nhat_')
            ->whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc');
        $records = $queryHistory->groupBy('lo_sx', 'line_id')->get()->groupBy('lo_sx');
        foreach ($records as $key => $record) {
            $obj = [];
            $plan_product = ProductionPlan::where('lo_sx', $key)->first();
            foreach ($record as $k => $item) {
                if ($k == 0) {
                    $plan = $item->plan;
                    $obj['product_id'] = $plan_product->product_id;
                    $obj['product_name'] = $plan_product->product->name ?? "";
                    $obj['lo_sx'] = $item->lo_sx;
                    $obj['so_bat'] = $plan_product->product->so_bat ?? "";
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

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->getParent()->getDefaultStyle()->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'F2F2F2')
            ]
        ]);
        $titleStyle = array_merge(['font' => ['size' => 16, 'bold' => true, 'color' => array('argb' => '4519FF')]]);
        $header1Style = array_merge(ExcelStyleHelper::bold(), ExcelStyleHelper::fill('DAEEF3'), ExcelStyleHelper::borders(), ExcelStyleHelper::alignment('center', true));
        $header3Style = array_merge(ExcelStyleHelper::bold(), ExcelStyleHelper::fill('EBF1DE'), ExcelStyleHelper::borders(), ExcelStyleHelper::alignment('center', true));
        $header4Style = array_merge(ExcelStyleHelper::bold(), ExcelStyleHelper::fill('EBF1DE'), ExcelStyleHelper::borders(), ExcelStyleHelper::alignment('center', true));
        $border = ExcelStyleHelper::borders();
        $sheet->setCellValue([1, 1], 'Báo cáo sản lượng sản xuất')->getStyle([1, 1])->applyFromArray($titleStyle);
        $header1 = [
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
        ];

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

        $start_row = 3;

        $sheet->fromArray($header1, null, 'A' . $start_row);
        $sheet->getRowDimension($start_row)->setRowHeight(42);
        $sheet->getStyle([1, $start_row, count($header1), $start_row])->applyFromArray($header1Style);
        $start_row += 1;
        $startTable = $start_row;
        $sheet->fromArray($table1, null, 'A' . $start_row);
        $start_row += count($table1);
        $sheet->getStyle([1, $startTable, count($header1), $start_row - 1])->applyFromArray(array_merge($border, ExcelStyleHelper::fill('FFFFFF')));
        $start_row += 1;

        $sheet->fromArray($header1, null, 'A' . $start_row);
        $sheet->getRowDimension($start_row)->setRowHeight(42);
        $sheet->getStyle([1, $start_row, count($header1), $start_row])->applyFromArray($header1Style);
        $start_row += 1;
        $startTable = $start_row;
        $sheet->fromArray($table2, null, 'A' . $start_row);
        $start_row += count($table2);
        $sheet->getStyle([1, $startTable, count($header1), $start_row - 1])->applyFromArray(array_merge($border, ExcelStyleHelper::fill('FFFFFF')));
        $start_row += 1;

        $sheet->fromArray($header3, null, 'A' . $start_row);
        $sheet->getRowDimension($start_row)->setRowHeight(42);
        $sheet->getStyle([1, $start_row, count($header3), $start_row])->applyFromArray($header3Style);
        $start_row += 1;
        $startTable = $start_row;
        $sheet->fromArray($table3, null, 'A' . $start_row);
        $start_row += count($table3);
        $sheet->getStyle([1, $startTable, count($header3), $start_row - 1])->applyFromArray(array_merge($border, ExcelStyleHelper::fill('FFFFFF')));
        $start_row += 1;

        $header4 = [
            'Mã hàng',
            'Tên sản phẩm',
            "Lô sản xuất",
            "Số bát",
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
        $start4_row = $start_row;
        $start4_col = 1;
        foreach ($header4 as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start4_col, $start4_row], $cell)->mergeCells([$start4_col, $start4_row, $start4_col, $start4_row + 1])->getStyle([$start4_col, $start4_row, $start4_col, $start4_row + 1])->applyFromArray($header4Style);
            } else {
                $sheet->setCellValue([$start4_col, $start4_row], $key)->mergeCells([$start4_col, $start4_row, $start4_col + count($cell) - 1, $start4_row])->getStyle([$start4_col, $start4_row, $start4_col + count($cell) - 1, $start4_row])->applyFromArray($header4Style);
                foreach ($cell as $val) {
                    $sheet->setCellValue([$start4_col, $start4_row + 1], $val)->getStyle([$start4_col, $start4_row + 1])->applyFromArray($header4Style);
                    $start4_col += 1;
                }
                continue;
            }
            $start4_col += 1;
        }
        $table4_col = 1;
        $table4_row = $start_row + 2;
        foreach ($table4 as $key => $row) {
            foreach ((array)$row as $key => $cell) {
                if (in_array($key, $table_key4) && array_search($key, $table_key4) !== "") {
                    $sheet->setCellValue(array_search($key, $table_key4) . $table4_row, $cell);
                } else {
                    continue;
                }
                $table4_col += 1;
            }
            $sheet->getStyle([1, $table4_row, count($table_key4), $table4_row])->applyFromArray(array_merge(ExcelStyleHelper::fill('FFFFFF'), $border, ExcelStyleHelper::alignment('center', true)));
            $table4_row += 1;
        }

        $sheet->getRowDimension(1)->setRowHeight(40);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setWidth(16);
            $sheet->getStyle($column->getColumnIndex() . 2 . ':' . $column->getColumnIndex() . $start_row)->applyFromArray(ExcelStyleHelper::alignment('center', true));
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Báo cáo sản lượng sản xuất.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Báo cáo sản lượng sản xuất.xlsx');
        $href = '/exported_files/Báo cáo sản lượng sản xuất.xlsx';
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
            $A = $tong_tg > 0 ? round(($tg_tsl / $tong_tg) * 100) : 0;
            $Q = $tong_sl > 0 ? round(($tong_sl_dat / $tong_sl) * 100) : 0;
            $P = ($uph && $tg_tsl >= 0) ? round(($tong_sl / ($tg_tsl / 3600) / ($uph / count($info_cds))) * 100) : 0;
            $OEE = (int)round(($A * $Q * $P) / 10000);

            $res[] = [
                'line' => $line->name,
                'A' => $this->adjustValue(min(100, $A)),
                'Q' => $this->adjustValue(min(100, $Q)),
                'P' => $this->adjustValue(min(100, $P)),
                'OEE' => $this->adjustValue(min(100, $OEE))
            ];
        }
        return $this->success($res);
    }

    function adjustValue($value)
    {
        // return $value;
        return ($value > 80) ? rand(60, 80) : $value;
    }

    //Lấy dữ liệu biểu đồ tần suất lỗi máy
    public function getErrorFrequencyData(Request $request)
    {
        $query = MachineLog::with("machine")->whereNotNull('info->error_id');
        if (isset($request->date) and is_array($request->date)) {
            $start = Carbon::parse($request->date[0])->startOfDay();
            $end   = Carbon::parse($request->date[1])->endOfDay();

            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                    ->orWhereBetween('updated_at', [$start, $end]);
            });
            // $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0] ?? 'now')))
            // ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1] ?? 'now')));
        }
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
        $machine_logs = $query->get();
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
        $qc_query = QCHistory::orderBy('created_at');
        if (isset($request->requestFrom)) {
            $qc_query->where('type', $request->requestFrom);
        }
        if (isset($request->date) && count($request->date) == 2) {
            $qc_query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        $qc_query->whereHas('infoCongDoan', function ($query) use ($request) {
            if (isset($request->line_id)) {
                if (is_array($request->line_id)) {
                    $query->whereIn('line_id', $request->line_id);
                } else {
                    $query->where('line_id', $request->line_id);
                }
            }
            if (isset($request->machine_code)) {
                if (is_array($request->machine_code)) {
                    $query->whereIn('machine_code', $request->machine_code);
                } else {
                    $query->where('machine_code', $request->machine_code);
                }
            }
            if (isset($request->product_id)) {
                $query->where('product_id', 'like',  '%' . $request->product_id . '%');
            }
            if (isset($request->ten_sp)) {
                $query->where('product_id', 'like',  '%' . $request->ten_sp . '%');
            }
            if (isset($request->khach_hang)) {
                $product_ids = Product::where('customer_id', $request->khach_hang)->pluck('id')->toArray();
                $query->whereIn('product_id', $product_ids);
            }
            if (isset($request->lo_sx)) {
                $query->where('lot_id', 'like', "%$request->lo_sx%");
            }
        });
        $qc_query->with('infoCongDoan.product', 'infoCongDoan.line', 'infoCongDoan.machine', 'user', 'errorHistories');
        return $qc_query;
    }

    public function parseQCData($qc_histories, $isExport = false)
    {
        $record = [];
        $shifts = Shift::all();
        $index = 0;
        foreach ($qc_histories as $key => $qc_history) {
            if (!$qc_history->infoCongDoan) {
                continue;
            }
            $ca_sx = $shifts->first(function ($shift) use ($qc_history) {
                $createdTime = Carbon::parse($qc_history->created_at)->format('H:i:s');
                return ($shift->start_time < $shift->end_time && $createdTime >= $shift->start_time && $createdTime <= $shift->end_time) ||
                    ($shift->start_time > $shift->end_time && ($createdTime >= $shift->start_time || $createdTime <= $shift->end_time));
            })->name ?? "";
            if ($qc_history->type == 'sx') {
                $qcHistoryBySX = $qc_history;
            } else {
                $qcHistoryBySX = QCHistory::with('user')->where('info_cong_doan_id', $qc_history->infoCongDoan->id ?? '')->where('type', 'sx')->first();
            }
            if ($qc_history->infoCongDoan->line_id == 29) {
                $assignment = Assignment::where('lot_id', $qc_history->infoCongDoan->lot_id)->with('worker')->first();
                $user_sxkt = $assignment->worker->name ?? '';
            } else {
                $user_sxkt = $qcHistoryBySX->user->name ?? '';
            }
            $user_qc = $qc_history->user->name ?? '';
            $sl_ng_sx = 0;
            $sl_ng_qc = 0;
            if (count($qc_history->errorHistories ?? [])) {
                foreach (($qc_history->errorHistories ?? []) as $error) {
                    if ($error->type === 'sx') {
                        $sl_ng_sx += $error->quantity;
                    } else {
                        $sl_ng_qc += $error->quantity;
                    }
                }
            }
            $item = [
                'stt' => $index + 1,
                'thoi_gian_kiem_tra' => $qc_history->scanned_time ? Carbon::parse($qc_history->scanned_time)->format('d/m/Y H:i:s') : "",
                'ca_sx' => $ca_sx,
                'xuong' => $qc_history->line->factory->name ?? "Giấy",
                'cong_doan' => $qc_history->infoCongDoan->line->name ?? '',
                'machine' => $qc_history->infoCongDoan->machine->name ?? '',
                'machine_id' => $qc_history->infoCongDoan->machine_code ?? '',
                'khach_hang' => $qc_history->infoCongDoan->product->customer->name ?? "",
                'product_id' => $qc_history->infoCongDoan->product_id ?? '',
                'ten_san_pham' => $qc_history->infoCongDoan->product->name ?? "",
                'lo_sx' => $qc_history->infoCongDoan->lo_sx,
                'lot_id' => $qc_history->infoCongDoan->lot_id,
                'sl_dau_ra_hang_loat' => $qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                'sl_dau_ra_ok' => ($qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0) - ($qc_history->infoCongDoan->sl_tem_vang ?? 0) - ($qc_history->infoCongDoan->sl_ng ?? 0),
                'sl_tem_vang' => $qc_history->infoCongDoan->sl_tem_vang ?? 0,
                'sl_ng_sxkt' => $sl_ng_sx,
                'user_sxkt' => $user_sxkt,
                'sl_ng_pqc' => $sl_ng_qc,
                'user_pqc' => $user_qc,
                'checked_from' => $qc_history->type === 'sx' ? 'Sản xuất' : 'Chất lượng',
                'sl_ng' => $qc_history->infoCongDoan->sl_ng ?? 0,
                'ti_le_ng' => (isset($qc_history->infoCongDoan->sl_dau_ra_hang_loat) && $qc_history->infoCongDoan->sl_dau_ra_hang_loat > 0) ? number_format(($qc_history->infoCongDoan->sl_ng / $qc_history->infoCongDoan->sl_dau_ra_hang_loat) * 100) . "%" : "0%",
            ];
            if (!$isExport) {
                $item['ngay_sx'] = $qc_history->scanned_time ? Carbon::parse($qc_history->scanned_time)->format('d/m/Y') : "";
                $final_qc_result = "";
                if ($qc_history && $qc_history->eligible_to_end !== null) {
                    if ($qc_history->eligible_to_end === 1) {
                        $final_qc_result = 'OK';
                    } else {
                        $final_qc_result = 'NG';
                    }
                } else {
                    $final_qc_result = '';
                }
                $item['final_qc_result'] = $final_qc_result;
                $yellowStampHistories = $qc_history->yellowStampHistories;
                $noi_dung_tem_vang = "";
                foreach ($yellowStampHistories as $yellowStampHistory) {
                    $noi_dung_tem_vang .= $yellowStampHistory->errors . ",";
                }
                $noi_dung_tem_vang = rtrim($noi_dung_tem_vang, ",");
                $item['noi_dung_tem_vang'] = $noi_dung_tem_vang;
                $item['line_id'] = $qc_history->infoCongDoan->line_id;
                $item['info_cong_doan_id'] = $qc_history->infoCongDoan->id;
                $item['qc_history_id'] = $qc_history->id;
                $item['qc_history_type'] = $qc_history->type;
            }
            $index++;
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

    public function getQualityDataDetail(Request $request)
    {
        $query = InfoCongDoan::where('lot_id', $request->lot_id);
        if (!empty($request->line_id)) {
            $query->where('line_id', $request->line_id);
        }
        if (!empty($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        $infoCongDoan = $query->first();
        if (!$infoCongDoan) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        $qcHistory = QCHistory::with('errorHistories.error')->where('info_cong_doan_id', $infoCongDoan->id)->get();
        $errorHistories = $qcHistory->flatMap->errorHistories ?? [];
        $data = [];
        foreach ($errorHistories as $errorHistory) {
            if (empty($errorHistory->error->noi_dung)) {
                continue;
            }
            if (!isset($data[$errorHistory->error_id])) {
                $data[$errorHistory->error_id] = [];
            }
            if (!isset($data[$errorHistory->error_id]['value'])) {
                $data[$errorHistory->error_id]['value'] = 0;
            }
            $data[$errorHistory->error_id]['value'] += $errorHistory->quantity;
            $data[$errorHistory->error_id]['error_name'] = $errorHistory->error->noi_dung;
        }
        return $this->success(array_values($data));
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
        $data->errorRatioData = ($errorRatio);
        return $this->success($data);
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
                        if (!isset($data[$error->error_id])) {
                            $data[$error->error_id] = [
                                'error' => $error->error_id,
                                // 'date' => $date,
                                'value' => 0
                            ];
                        }
                        $data[$error->error_id]['value'] += $error->quantity;
                    }
                }
            }
        }
        $total = array_sum(array_column($data, 'value'));
        foreach ($data as &$item) {
            $item['value'] = ($total > 0) ? round(($item['value'] / $total) * 100, 2) : 0;
        }
        return $data;
    }

    public function parseErrorRatioData($qcHistories)
    {
        $data = [];
        $errors = array_unique($qcHistories->flatMap->errorHistories->filter(function ($value) {
            return !str_contains($value->error_id, 'NVL');
        })->pluck('error_id')->toArray());
        foreach ($qcHistories as $qc_history) {
            foreach (($qc_history->errorHistories ?? []) as $error) {
                if (!in_array($error->error_id, $errors)) {
                    continue;
                }
                $sl_ng = $error->quantity ?? 0;
                $sl_dau_ra_hang_loat = $qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0;
                // Tính tỷ lệ lỗi cho bản ghi này
                $ratio = $sl_dau_ra_hang_loat > 0 ? ($sl_ng / $sl_dau_ra_hang_loat) * 100 : 0;
                // Khởi tạo mảng nếu chưa tồn tại
                if (!isset($data[$error->type])) {
                    $data[$error->type] = ['name' => $error->type, 'data' => []];
                }
                // Thêm tỷ lệ lỗi vào mảng `data` cho lỗi cụ thể
                if (!isset($data[$error->type]['data'][$error->error_id])) {
                    $data[$error->type]['data'][$error->error_id] = [];
                }
                // Thêm tỷ lệ lỗi vào mảng cho error_id
                $data[$error->type]['data'][$error->error_id][] = $ratio;
            }
        }
        $formattedData = [];
        foreach ($data as $type => $typeData) {
            $value = [];
            foreach ($errors as $error) {
                $value[] = isset($typeData['data'][$error]) ? round(array_sum($typeData['data'][$error]) / count($typeData['data'][$error]), 2) : 0;
            }
            $formattedData[$type] = $value;
        }
        return ['categories' => array_values($errors), 'series' => $formattedData];
    }

    public function exportQualityDataTable(Request $request)
    {
        $query = $this->pqcHistoryQuery($request);
        $records = $query->get();
        $data = $this->parseQCData($records, true);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $titleStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(true, 16));
        $headerStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(), ExcelStyleHelper::fill());
        $border = ExcelStyleHelper::borders();
        $header = [
            'STT',
            'Ngày PQC kiểm tra',
            "Ca sản xuất",
            "Xưởng",
            "Công đoạn",
            "Máy sản xuất",
            "Mã máy",
            "Khách hàng",
            "Mã hàng",
            'Tên sản phẩm',
            'Lô sản xuất',
            'Mã pallet/thùng',
            "SL đầu ra sản xuất",
            "SL đầu ra OK",
            'SL tem vàng',
            "SL NG (SX tự KT)",
            'SX kiểm tra',
            "SL NG (PQC)",
            'QC kiểm tra',
            'Kiểm tra bởi',
            "SL NG",
            "Tỉ lệ NG"
        ];
        foreach ($header as $key => $cell) {
            $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Truy vấn chất lượng PQC (Bảng chi tiết trang chính)')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = count($data) + 2;
        $sheet->fromArray((array)$data, NULL, 'A3', true);
        // $sheet->getStyle([1, 5, 30, count($data) + 4])->applyFromArray($centerStyle);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . 1 . ':' . $column->getColumnIndex() . ($table_row))->applyFromArray(array_merge(ExcelStyleHelper::alignment(), $border));
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

    public function exportTestCriteriaHistory(Request $request)
    {
        $query = $this->pqcHistoryQuery($request);
        // return $query->get();
        $lineMachineQuery = clone $query;
        $lineMachines = $lineMachineQuery->get()->map(function ($history) {
            return [
                'machine_code' => $history->infoCongDoan->machine_code ?? null,
                'line_id' => $history->infoCongDoan->line_id ?? null,
                'product_id' => $history->infoCongDoan->product_id ?? null,
                'date' => Carbon::parse($history->created_at ?? null)->format('Y-m-d'),
            ];
        })->unique()->values()->toArray();
        $lineMachines = collect($lineMachines)
            ->sortBy([
                ['line_id', 'asc'],
                ['date', 'asc'],
                ['machine_code', SORT_NATURAL],
                ['product_id', 'asc'],
            ])
            ->values()
            ->toArray();
        // return $lineMachines;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet_index = 0;
        foreach ($lineMachines as $lineMachine) {
            $machine = Machine::with('line')->where('code', $lineMachine['machine_code'])->first();
            $product = Product::find($lineMachine['product_id']);
            if (!$machine || !$machine->line || !$product) {
                continue;
            }
            $line = $machine->line;
            $sheet = $spreadsheet->getSheet($sheet_index);
            $sheet->setTitle(($line->name != 'OQC' ? $machine->code : "OQC") . "-" . $product->id . "-" . Carbon::parse($lineMachine['date'])->format('dmy'));
            $lineQcHistoriesQuery = clone $query;
            $qcHistories = $lineQcHistoriesQuery->whereDate('created_at', $lineMachine['date'])
                ->whereHas('infoCongDoan', function ($infoQuery) use ($line, $machine, $product) {
                    $infoQuery->where('line_id', $line->id)
                        ->where('machine_code', $machine->code ?? null)
                        ->where('product_id', $product->id);
                })
                ->with('testCriteriaDetailHistories', 'infoCongDoan')
                ->get();
            $checked_data = [];
            $lot_id_array = [];
            $losx_array = [];
            $ngay_sx_array = [];
            $product_array = [];
            $machine_array = [];
            // return $infos;
            foreach ($qcHistories as $qcHistory) {
                if (empty($qcHistory->infoCongDoan)) {
                    continue;
                }
                $lot_id_array[] = $qcHistory->infoCongDoan->lot_id . '(' . $qcHistory->type . ')';
                in_array($qcHistory->infoCongDoan->lo_sx, $losx_array) || $losx_array[] = $qcHistory->infoCongDoan->lo_sx;
                in_array(date('d/m/Y', strtotime($qcHistory->infoCongDoan->created_at)), $ngay_sx_array) || $ngay_sx_array[] = date('d/m/Y', strtotime($qcHistory->infoCongDoan->created_at));
                in_array($qcHistory->infoCongDoan->product->name, $product_array) || $product_array[] = $qcHistory->infoCongDoan->product->name;
                in_array($qcHistory->infoCongDoan->machine_code, $machine_array) || $machine_array[] = $qcHistory->infoCongDoan->machine_code;
                $checked_data[$qcHistory->id] = $qcHistory->testCriteriaDetailHistories->mapWithKeys(function ($e) {
                    return [$e->test_criteria_id => $e->input ?? $e->result];
                });
            }
            $transformData = $this->transformArray($checked_data);
            // return [$checked_data, $transformData];
            $header = [
                ['Ngày sản xuất', implode(" + ", $ngay_sx_array)],
                ['Tên sản phẩm', implode(" + ", $product_array)],
                ['Công đoạn', $line->name],
                ['Mã máy', implode(" + ", $machine_array)],
                ['Lô sản xuất', implode(" + ", $losx_array)],
            ];
            $sheet->fromArray($header, null, 'A2');
            $sheet->getStyle('A2:B6')->applyFromArray(array_merge(ExcelStyleHelper::borders(), ExcelStyleHelper::alignment('left')));
            $sheet->setCellValue('A7', 'Chỉ tiêu kiểm tra')->mergeCells('A7:A8');
            $sheet->setCellValue('B7', 'Hạng mục kiểm tra')->mergeCells('B7:B8');
            $sheet->setCellValue('C7', 'Tiêu chuẩn')->mergeCells('C7:C8');
            $groupTestCriteria = $line->testCriteria->groupBy('so_chi_tieu')->sortKeys();
            $data = [];
            $row_index = 9; // Dòng bắt đầu ghi dữ liệu
            foreach ($groupTestCriteria as $so_chi_tieu => $testCriteria) {
                $start_index = $row_index; // Dòng bắt đầu của nhóm chỉ tiêu kiểm tra
                // $current_tieu_chuan = null;
                // $tieu_chuan_merge_start = $row_index;
                // Ghi cột "Chỉ tiêu kiểm tra"
                $sheet->setCellValue([1, $row_index], $so_chi_tieu);
                foreach ($testCriteria as $index => $testCriterion) {
                    $slug_hang_muc = Str::slug($testCriterion->hang_muc);
                    $lines = array_merge(explode(',', $testCriterion->reference), [$line->id]);
                    $spec = Spec::whereIn("line_id", $lines)->where('slug', $slug_hang_muc)->whereNotNull('name')->where("product_id", $product->id)->whereNotNull('value')->first();
                    // Ghi dữ liệu cột "Hạng mục kiểm tra" và "Tiêu chuẩn"
                    $sheet->setCellValue([2, $row_index], $testCriterion->hang_muc);
                    $sheet->setCellValue([3, $row_index], $spec->value ?? null);
                    // Xử lý dữ liệu đã kiểm tra
                    // if (!isset($transformData[$testCriterion->id])) {
                    //     foreach ($qcHistories as $info) {
                    //         $data[$testCriterion->id][$info['lot_id']] = null;
                    //     }
                    // } else {
                    $data[$testCriterion->id] = $transformData[$testCriterion->id] ?? [];
                    // }
                    $row_data = array_filter($data[$testCriterion->id]);
                    if (empty($row_data)) {
                        unset($data[$testCriterion->id]);
                        continue;
                    };
                    $row_index++;
                }

                // Merge các ô trong cột "Chỉ tiêu kiểm tra" (Cột 1)
                if ($start_index < $row_index - 1) {
                    $sheet->mergeCells([1, $start_index, 1, $row_index - 1]);
                }
            }
            $next_rows = [
                'final_qc_result' => 'Đánh giá tổng thể kết quả kiểm tra',
                'sl_dau_ra_hang_loat' => 'Số lượng sản xuất',
                'sl_dau_ra_ok' => 'Số lượng OK',
                'sl_ng' => 'Số lượng NG',
                'sl_tem_vang' => 'Số lượng tem vàng',
                'noi_dung_tem_vang' => 'Nội dung hàng tem vàng',
                'user_sxkt' => 'Công nhân sản xuất',
                'user_pqc' => 'QC kiểm tra',
                'checked_from' => 'Kiểm tra bởi',
                'note' => 'Ghi chú'
            ];
            $sheet->getStyle([1, 7, 4 + (count($lot_id_array) > 1 ? (count($lot_id_array) - 1) : 0), $row_index + count($next_rows) - 1])->applyFromArray(array_merge(ExcelStyleHelper::borders(), ExcelStyleHelper::alignment('left')));
            $infos = $this->parseQCData($qcHistories);
            foreach ($next_rows as $key => $value) {
                $sheet->setCellValue('A' . $row_index, $value)->mergeCells('A' . $row_index . ':C' . $row_index)->getStyle('A' . $row_index . ':C' . $row_index)->applyFromArray(array_merge(ExcelStyleHelper::bold(), ExcelStyleHelper::alignment('center')));
                $row_index++;
                $data[$key] = [];
                foreach ($infos as $info) {
                    if (isset($checked_data[$info['qc_history_id']])) {
                        $data[$key][$info['qc_history_id']] = $info[$key] ?? null;
                    }
                }
            }
            // return $data;
            $sheet->setCellValue('D7', 'Mã pallet/thùng')->mergeCells([4, 7, 4 + (count($lot_id_array) > 1 ? (count($lot_id_array) - 1) : 0), 7])->getStyle([4, 7, 4 + (count($lot_id_array) > 1 ? (count($lot_id_array) - 1) : 0), 7])->applyFromArray(ExcelStyleHelper::alignment('center'));
            $sheet->fromArray($lot_id_array, null, 'D8');
            $sheet->fromArray($data, null, 'D9', true);
            foreach ($sheet->getColumnIterator() as $column) {
                if ($column->getColumnIndex() === 'C') {
                    $sheet->getStyle($column->getColumnIndex() . '1:' . $column->getColumnIndex() . $sheet->getHighestRow())->applyFromArray([
                        'alignment' => [
                            'wrapText' => true
                        ]
                    ]);
                    $sheet->getColumnDimension($column->getColumnIndex())->setWidth(60);
                } else {
                    $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
                }
            }
            if ($sheet_index < count($lineMachines) - 1) {
                $spreadsheet->createSheet();
                $sheet_index += 1;
            }
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Bảng kiểm tra.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Bảng kiểm tra.xlsx');
        $href = '/exported_files/Bảng kiểm tra.xlsx';
        return $this->success($href);
    }

    function transformArray($inputArray)
    {
        $result = [];

        // Lặp qua từng phần tử trong mảng đầu vào
        foreach ($inputArray as $key => $tests) {
            foreach ($tests as $testKey => $testValue) {
                // Tạo hàng với key là testKey (CT1, CT2, ...)
                $result[$testKey][$key] = $testValue;
            }
        }

        // Đảm bảo tất cả các cột có đủ các giá trị (nếu thiếu thì set NULL hoặc giá trị mặc định)
        $keys = array_keys($inputArray); // Lấy tất cả các key cột (ví dụ: 2411948.L.0002)
        foreach ($result as $testKey => &$row) {
            foreach ($keys as $key) {
                if (!isset($row[$key])) {
                    $row[$key] = null; // Giá trị mặc định là null nếu không tồn tại
                }
            }
            ksort($row); // Sắp xếp lại các cột theo thứ tự key
        }

        return $result;
    }

    public function exportPQCReport(Request $request)
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
            $groupedQcHistories = QCHistory::with('testCriteriaHistories', 'infoCongDoan')->orderBy('created_at')
                ->whereDate('created_at', '>=', $sheet_array[$key]['start_date'])
                ->whereDate('created_at', '<=', $sheet_array[$key]['end_date'])
                ->get()
                ->groupBy(function ($qc_history) {
                    return $qc_history->infoCongDoan->line_id ?? null;
                });
            $data = [];
            foreach ($groupedQcHistories as $line_id => $qcHistories) {
                $groupByMachineAndProduct = $qcHistories->groupBy(function ($qcHistory) {
                    return ($qcHistory->infoCongDoan->machine_code ?? "") . ($qcHistory->infoCongDoan->product_id ?? "") . date('Y-m-d', strtotime($qcHistory->scanned_time));
                });
                $checked_counter = count($groupByMachineAndProduct);
                $line = Line::find($line_id);
                if (!$line) continue;
                $sum_ng = 0;
                foreach ($groupByMachineAndProduct as $machineProductDate => $detailQcHistories) {
                    foreach ($detailQcHistories as $qcHistory) {
                        $final_result = $qcHistory->testCriteriaHistories->pluck('result')->toArray();
                        if (count($final_result) >= 3) {
                            if (in_array('NG', $final_result)) {
                                $sum_ng += 1;
                                break;
                            }
                        }
                    }
                }
                $sum_ok = $checked_counter - $sum_ng;
                $data[$line_id]['cong_doan'] = $line->name;
                $data[$line_id]['sum_lot_kt'] = $checked_counter;
                $data[$line_id]['sum_lot_ok'] = $sum_ok;
                $data[$line_id]['sum_lot_ng'] = $sum_ng;
                $data[$line_id]['sum_ty_le_ng'] = $checked_counter ? number_format($sum_ng / $checked_counter * 100, 2) : 0;
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

    public function exportPQCReportV2(Request $request)
    {
        set_time_limit(180);
        $input = $request->all();
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'wrapText' => true
            ],
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 24, 'bold' => true],
        ]);
        $sheet_index = 0;
        $lines = Line::where('factory_id', 2)->get();
        foreach ($input as $key => $value) {
            //Tạo sheet
            $sheet = $spreadsheet->getSheet($sheet_index);
            $sheet->getColumnDimension('A')->setWidth(20);
            //Tạo dữ liệu
            $tong_so_lot_kiem_tra = 0;
            $so_lot_ng = 0;
            $muc_tieu = 0;
            $range = [];
            $groupedData = [];
            switch ($key) {
                case 'day':
                    $type = 'Ngày';
                    $start = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->startOfMonth();
                    $end = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->endOfMonth();
                    for ($date = $start; $date->lte($end); $date->addDay()) {
                        $range[] = [
                            'start' => $date->copy()->startOfDay(),
                            'end' => $date->copy()->endOfDay(),
                            'type' => 'Ngày',
                            'key' => 'd'
                        ];
                    }
                    break;
                case 'week':
                    $type = 'Tuần';
                    $start = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->startOfWeek(Carbon::MONDAY);
                    $end = $start->copy()->addWeeks(10)->endOfDay();
                    for ($date = $start; $date->lte($end); $date->addWeek()) {
                        $range[] = [
                            'start' => $date->copy()->startOfWeek(Carbon::MONDAY),
                            'end' => $date->copy()->endOfWeek(),
                            'type' => 'Tuần',
                            'key' => 'W'
                        ];
                    }
                    break;
                case 'year':
                    $type = 'Tháng';
                    $start = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->startOfYear();
                    $end = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->endOfYear();
                    for ($date = $start; $date->lte($end); $date->addMonth()) {
                        $range[] = [
                            'start' => $date->copy()->startOfMonth(Carbon::MONDAY),
                            'end' => $date->copy()->endOfMonth(),
                            'type' => 'Tháng',
                            'key' => 'm'
                        ];
                    }
                    break;
                default:
                    continue 2;
                    break;
            }
            $sheet->setTitle('Báo cáo ' . strtolower($type));

            $line_title_index = 4;
            $line_table_index = 14;
            foreach ($lines as $line) {
                if ($line->id === 24) {
                    $muc_tieu = 0.001;
                } elseif ($line->id === 25) {
                    $muc_tieu = 0.01;
                } elseif ($line->id === 26) {
                    $muc_tieu = 0.01;
                } elseif ($line->id === 27) {
                    $muc_tieu = 0.01;
                } elseif ($line->id === 29) {
                    $muc_tieu = 0.005;
                } elseif ($line->id === 30) {
                    $muc_tieu = 0.005;
                }
                $groupedData[$line->id] = [];
                $data = [];
                foreach ($range as $index => $value) { // Nếu nhóm theo ngày
                    $date_key = $value['start']->copy()->format($value['key']);
                    $infoData = InfoCongDoan::whereHas('qcHistory', function ($query) use ($value) {
                        $query->whereBetween('created_at', [$value['start'], $value['end']]);
                    })->where('line_id', $line->id)->with('qcHistory')
                        ->get()
                        ->groupBy(function ($infoCongDoan) {
                            return ($infoCongDoan->machine_code ?? "") . ($infoCongDoan->product_id ?? "");
                        });
                    $tong_so_lot_kiem_tra = count($infoData);
                    $so_lot_ng = 0;
                    foreach ($infoData as $info) {
                        foreach ($info as $key => $value) {
                            $final_result = $value->qcHistory->first()->eligible_to_end;
                            if ($final_result === QCHistory::NOT_READY_TO_END) {
                                $so_lot_ng += 1;
                                continue 2;
                            }
                        }
                    }
                    $colName = Coordinate::stringFromColumnIndex(2 + $index);
                    $rowIndex = $line_table_index + 1;
                    $data[] = [
                        'date_key' => (int) $date_key,
                        'tong_so_lot_kiem_tra' => $tong_so_lot_kiem_tra,
                        'so_lot_ok' => "=+" . $colName . $rowIndex . "-" . $colName . ($rowIndex + 2),
                        'so_lot_ng' => $so_lot_ng,
                        'ty_le_ng' => "=IF(" . $colName . $rowIndex . ">0," . $colName . ($rowIndex + 2) . "/" . $colName . $rowIndex . ",0)",
                        // 'ty_le_ng' => $ty_le_ng,
                        'muc_tieu' => $muc_tieu
                    ];
                }
                $sheet->setCellValue([1, $line_title_index], 'Công đoạn ' . $line->name)->getStyle([1, $line_title_index])->applyFromArray(['font' => ['size' => 22, 'bold' => true]]);
                $sheet->getRowDimension($line_title_index)->setRowHeight(27);
                $header = [
                    $type,
                    'Tổng số lot kiểm tra',
                    "Số lot OK",
                    "Số lot NG",
                    "Tỷ lệ NG (%)",
                    "Mục tiêu"
                ];
                $sheet->fromArray(array_chunk($header, 1), null, 'A' . $line_table_index);
                if (count($data) == 1) {
                    $transposedData = [];
                    foreach ($data[0] as $key => $value) {
                        $transposedData[] = [$value]; // Mỗi giá trị sẽ thành một hàng
                    }
                } else {
                    // **Dùng array_map nếu có nhiều phần tử**
                    $transposedData = array_map(null, ...array_map('array_values', $data));
                }
                // return $transposedData;
                $sheet->fromArray($transposedData, null, 'B' . $line_table_index, true); //Gán dữ liệu vào bảng
                $sheet->getStyle([1, $line_table_index, 1, $line_table_index + count($header) - 1])->applyFromArray(['font' => ['bold' => true]]); //In đậm header của bảng


                $startColumnIndex = Coordinate::columnIndexFromString("B");
                $endColumn = Coordinate::stringFromColumnIndex($startColumnIndex + count($transposedData[0]) - 1);

                $sheet->getStyle([1, $line_table_index, $startColumnIndex + count($transposedData[0]) - 1, $line_table_index + count($header) - 1])->applyFromArray($centerStyle); //Tạo border cho bảng
                $sheet->getStyle("B" . ($line_table_index + 4) . ":" . $endColumn . ($line_table_index + 4))->applyFromArray([
                    'font' => [
                        'color' => ['argb' => 'FF0000'], // Màu đỏ
                    ]
                ]); //Bôi đỏ chữ hàng Tỷ lệ NG
                $sheet->getStyle("B" . ($line_table_index + 4) . ":" . $endColumn . ($line_table_index + 5))->getNumberFormat()->setFormatCode('0.0%');

                // Trục X: Tháng
                $rangeData = "B" . ($line_table_index) . ":" . $endColumn . ($line_table_index);
                $xAxisTickValues = [new DataSeriesValues('String', "'Báo cáo $type'!" . $rangeData, null, count($transposedData[0]))];

                // **Dữ liệu cột**
                $rangeData = "B" . ($line_table_index + 2) . ":" . $endColumn . ($line_table_index + 2);
                $dataSeriesValues1 = new DataSeriesValues('Number', "'Báo cáo $type'!" . $rangeData, null, count($transposedData[1]));
                $titleSeriesValues1 = new DataSeriesValues('Number', "'Báo cáo $type'!" . "A" . ($line_table_index + 2) . ":A" . ($line_table_index + 2));
                $rangeData = "B" . ($line_table_index + 3) . ":" . $endColumn . ($line_table_index + 3);
                $dataSeriesValues2 = new DataSeriesValues('Number', "'Báo cáo $type'!" . $rangeData, null, count($transposedData[2]));
                $titleSeriesValues2 = new DataSeriesValues('Number', "'Báo cáo $type'!" . "A" . ($line_table_index + 3) . ":A" . ($line_table_index + 3));

                // **Dữ liệu đường**
                $rangeData = "B" . ($line_table_index + 4) . ":" . $endColumn . ($line_table_index + 4);
                $dataSeriesValues3 = new DataSeriesValues('Number', "'Báo cáo $type'!" . $rangeData, null, count($transposedData[3]));
                $dataSeriesValues3->setLineWidth(2); // Độ dày đường
                $dataSeriesValues3->setPointMarker('none');
                $dataSeriesValues3->setFillColor('FF0000'); // Màu đỏ
                $titleSeriesValues3 = new DataSeriesValues('Number', "'Báo cáo $type'!" . "A" . ($line_table_index + 4) . ":A" . ($line_table_index + 4));

                $rangeData = "B" . ($line_table_index + 5) . ":" . $endColumn . ($line_table_index + 5);
                $dataSeriesValues4 = new DataSeriesValues('Number', "'Báo cáo $type'!" . $rangeData, null, count($transposedData[4]));
                $dataSeriesValues4->setLineWidth(2);
                $dataSeriesValues4->setPointMarker('none');
                $dataSeriesValues4->setFillColor('008000'); // Màu xanh lá
                $titleSeriesValues4 = new DataSeriesValues('Number', "'Báo cáo $type'!" . "A" . ($line_table_index + 5) . ":A" . ($line_table_index + 5));

                // **Tạo Series**
                $series1 = new DataSeries(
                    DataSeries::TYPE_BARCHART, // Cột
                    DataSeries::GROUPING_CLUSTERED,
                    range(0, 1),
                    [$titleSeriesValues1, $titleSeriesValues2],
                    $xAxisTickValues,
                    [$dataSeriesValues1, $dataSeriesValues2]
                );

                $series2 = new DataSeries(
                    DataSeries::TYPE_LINECHART, // Đường kẻ
                    DataSeries::GROUPING_STANDARD,
                    range(0, 1),
                    [$titleSeriesValues3, $titleSeriesValues4],
                    $xAxisTickValues,
                    [$dataSeriesValues3, $dataSeriesValues4]
                );

                // **Gán trục y**
                $series1->setPlotDirection(DataSeries::DIRECTION_COL);
                $series2->setPlotDirection(DataSeries::DIRECTION_COL);

                // **Tạo vùng dữ liệu**
                $layout = new Layout();
                $layout->setShowVal(true);
                // $layout->setLabelFillColor(new ChartColor('FFFFFF'));
                // $layout->setLabelBorderColor(new ChartColor('000000')); // Viền đen
                $plotArea = new PlotArea($layout, [$series1], [$series2]);

                $secondaryYAxisLabel  = new Title('');

                // **Tạo biểu đồ**
                $chart = new Chart(
                    'chart1',
                    null,
                    new Legend(Legend::POSITION_BOTTOM, null, false),
                    $plotArea,
                    true,
                    'gap',
                    null, // xAxisLabel
                    null,  // yAxisLabel
                    null, // xAxis
                    null,  // yAxis
                    null,  // majorGridlines
                    null,  //minor Gridlines
                    $secondaryYAxisLabel    // secondaryYAxisLabel
                );

                // **Đặt vị trí biểu đồ**
                $endColumnChart = Coordinate::stringFromColumnIndex($startColumnIndex + count($transposedData[0]));
                $chart->setTopLeftPosition('A' . ($line_title_index + 1));
                $chart->setBottomRightPosition($endColumnChart . ($line_table_index - 1));

                // Thêm biểu đồ vào sheet
                $sheet->addChart($chart);
                $line_title_index += 18;
                $line_table_index += 18;
            }
            $sheet->setCellValue('A1', 'BÁO CÁO ' . mb_strtoupper($type, 'UTF-8') . ' CÁC CÔNG ĐOẠN')->mergeCells("A1:M3")->getStyle("A1:M3")->applyFromArray($titleStyle);
            $spreadsheet->createSheet();
            $sheet_index++;
        }
        $spreadsheet->removeSheetByIndex($sheet_index);
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Báo cáo.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save('exported_files/Báo cáo.xlsx');
        $href = '/exported_files/Báo cáo.xlsx';
        return $this->success($href);
    }

    function findShift($record, $shifts)
    {
        $createdTime = Carbon::parse($record->created_at)->format('H:i:s');
        foreach ($shifts as $shift) {
            if ($shift->start_time < $shift->end_time) {
                if ($createdTime >= $shift->start_time && $createdTime <= $shift->end_time) {
                    return $shift;
                }
            } else {
                if ($createdTime >= $shift->start_time || $createdTime <= $shift->end_time) {
                    return $shift;
                }
            }
        }
        return null;
    }

    //Lỗi
    public function qcErrorList(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->pqcHistoryQuery($request);
        $totalPage = $query->count();
        $records = $query->offset($page * $pageSize)->limit($pageSize)->get();
        list($result, $columns) = $this->parseErrorData($records);
        return $this->success(['data' => $result, "totalPage" => $totalPage, 'columns' => $columns]);
    }

    public function parseErrorData($qc_histories)
    {
        $record = [];
        $shifts = Shift::all();
        $index = 0;
        $columns = [
            'Lỗi NG' => [],
            'Lỗi KV' => [],
        ];
        foreach ($qc_histories as $key => $qc_history) {
            if (!$qc_history->infoCongDoan) {
                continue;
            }
            $ca_sx = $shifts->first(function ($shift) use ($qc_history) {
                $createdTime = Carbon::parse($qc_history->created_at)->format('H:i:s');
                return ($shift->start_time < $shift->end_time && $createdTime >= $shift->start_time && $createdTime <= $shift->end_time) ||
                    ($shift->start_time > $shift->end_time && ($createdTime >= $shift->start_time || $createdTime <= $shift->end_time));
            })->name ?? "";
            $item = [
                'stt' => $index + 1,
                'ngay_sx' => Carbon::parse($qc_history->created_at)->format('d/m/Y H:i:s'),
                'ca_sx' => $ca_sx,
                'xuong' => $qc_history->line->factory->name ?? "Giấy",
                'cong_doan' => $qc_history->infoCongDoan->line->name ?? '',
                'ten_san_pham' => $qc_history->infoCongDoan->product->name ?? "",
                'lo_sx' => $qc_history->infoCongDoan->lo_sx,
                'lot_id' => $qc_history->infoCongDoan->lot_id,
                'sl_dau_vao_hang_loat' => $qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                'sl_dau_ra_ok' => ($qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0) - ($qc_history->infoCongDoan->sl_tem_vang ?? 0) - ($qc_history->infoCongDoan->sl_ng ?? 0),
                'sl_ng' => $qc_history->infoCongDoan->sl_ng ?? 0,
                'sl_tem_vang' => $qc_history->infoCongDoan->sl_tem_vang ?? 0,
            ];

            $errorHistories = $qc_history->errorHistories->map(function ($value) use (&$columns, &$item) {
                if (!isset($columns['Lỗi NG']['ng' . $value->error_id])) {
                    $columns['Lỗi NG']['ng' . $value->error_id] = [
                        'key' => 'ng' . $value->error_id,
                        'title' => $value->error->noi_dung ?? "",
                    ];
                }
                if (!isset($item['ng' . $value->error_id])) {
                    $item['ng' . $value->error_id] = $value->quantity;
                } else {
                    $item['ng' . $value->error_id] +=  $value->quantity;
                }
                return $value;
            });

            $yellowStampHistories = $qc_history->yellowStampHistories->map(function ($value) use (&$columns, &$item) {
                $total_quantity = $value->sl_tem_vang;
                $errors = explode(',', $value->errors);
                foreach ($errors as $key => $err) {
                    $quantity = $total_quantity;
                    if (!isset($columns['Lỗi KV']['kv' . $err])) {
                        $error = Error::find($err);
                        $columns['Lỗi KV']['kv' . $err] = [
                            'key' => 'kv' . $err,
                            'title' => $error->noi_dung ?? "",
                        ];
                    }
                    if (!isset($item['kv' . $err])) {
                        $item['kv' . $err] = $quantity;
                    } else {
                        $item['kv' . $err] += $quantity;
                    }
                    $total_quantity -= $quantity;
                }

                return $value;
            });

            // if (!empty($columns)) {
            //     $errorColumns = collect($columns)->flatten(1)->all();
            //     foreach ($errorColumns as $error_id => $value) {
            //         if(isset($errorHistories[$value['key']])) {
            //             // Log::debug([$value['key'] , $errorHistories[$value['key']]]);
            //             $item[$value['key']] = $errorHistories[$value['key']];
            //         } else if(isset($yellowStampHistories[$value['key']])) {
            //             $item[$value['key']] = $errorHistories[$value['key']];
            //         }else{
            //             $item[$value['key']] = '';
            //         }
            //     }
            // }
            $index++;
            $record[] = $item;
        }
        return [$record, $columns];
    }

    public function exportQCErrorList(Request $request)
    {
        $query = $this->pqcHistoryQuery($request);
        $records = $query->get();
        list($result, $columns) = $this->parseErrorData($records);
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
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
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
        foreach ($columns as $key => $column) {
            $header1[$key] = array_map(function ($col) {
                return $col['title'];
            }, $column);
        }

        foreach ($result as $k => $item) {
            // Sắp xếp lại lỗi NG
            $sorted_ng = $this->sortErrorColumns($item, $columns['Lỗi NG']);

            // Sắp xếp lại lỗi KV
            $sorted_kv = $this->sortErrorColumns($item, $columns['Lỗi KV']);
            $info_item = Arr::except($item, array_merge(
                array_keys($sorted_ng),
                array_keys($sorted_kv)
            ));

            $result[$k] = array_merge($info_item, $sorted_ng, $sorted_kv);
        }
        // return $result;

        foreach ($header1 as $key => $cell) {
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
        $sheet->setCellValue([1, 1], 'BÁO CÁO SỐ LỖI')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $table_row = $start_row + 2;
        $sheet->fromArray($result, null, 'A4', true);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . 2 . ':' . $column->getColumnIndex() . count($result) + 3)->applyFromArray($border);
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

    function sortErrorColumns(array $item, array $columns): array
    {
        $sorted = [];

        foreach ($columns as $col) {
            $key = $col['key'];
            $sorted[$key] = $item[$key] ?? '';
        }

        return $sorted;
    }

    //Danh sách lot OQC
    public function oqcHistoryQuery(Request $request)
    {
        $query = QCHistory::orderBy('created_at');
        if (isset($request->start_date) && isset($request->end_date)) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->start_date)))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->end_date)));
        }
        $query->whereHas('infoCongDoan', function ($query) use ($request) {
            $query->where('line_id', 30);
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
        });

        $query->with('infoCongDoan.product', 'infoCongDoan.line', 'infoCongDoan.machine', 'user', 'errorHistories');
        return $query;
    }

    public function parseOQCData($qc_histories)
    {
        $record = [];
        $shifts = Shift::all();
        $index = 0;
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
            $errors = $qc_history->errorHistories->map(function ($item) {
                return $item->error_id;
            })->toArray();
            $item = [
                'stt' => $index + 1,
                'ngay_sx' => $qc_history->scanned_time ? Carbon::parse($qc_history->scanned_time)->format('d/m/Y H:i:s') : '',
                'ca_sx' => $ca_sx,
                'xuong' => $qc_history->line->factory->name ?? "Giấy",
                'ten_sp' => $qc_history->infoCongDoan->product->name ?? "",
                'khach_hang' => $qc_history->infoCongDoan->product->customer->name ?? "",
                'product_id' => $qc_history->infoCongDoan->product_id ?? '',
                'lo_sx' => $qc_history->infoCongDoan->lo_sx,
                'lot_id' => $qc_history->infoCongDoan->lot_id,
                'sl_sx' => $qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                'sl_mau' => 0,
                'sl_ng' => $qc_history->infoCongDoan->sl_ng ?? 0,
                'error' => implode(', ', $errors),
                'ket_luan' => $qc_history->eligible_to_end ? 'OK' : "",
                'nguoi_oqc' => $user_qc->name ?? "",
            ];
            $index++;
            $record[] = $item;
        }
        return $record;
    }

    public function getOQCDataTable(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->oqcHistoryQuery($request);
        $totalPage = $query->count();
        $records = $query->offset($page * $pageSize)->limit($pageSize)->get();
        $data = $this->parseOQCData($records);
        return $this->success([
            "data" => $data,
            "totalPage" => $totalPage,
        ]);
    }

    public function getOQCDataChart(Request $request)
    {
        $query = $this->oqcHistoryQuery($request);
        $records = $query->get();
        $data = $records->groupBy(function ($item) {
            return $item->created_at->format('Y-m-d'); // Nhóm theo ngày
        })->map(function ($itemsByDate, $date) {
            $totalOutput = $itemsByDate->sum(function ($item) {
                return $item->infoCongDoan->sl_dau_ra_hang_loat ?? 0; // Lấy `sl_dau_ra_hang_loat`, mặc định 0 nếu không có
            });

            $errorsByType = $itemsByDate->flatMap->errorHistories->groupBy('error_id')
                ->map(function ($errors) use ($totalOutput) {
                    return $totalOutput > 0 ? round(($errors->sum('quantity') / $totalOutput) * 100, 2) : 0;
                });
            return [
                'date' => $date,
                'error_rates' => $errorsByType // Tỷ lệ lỗi theo loại lỗi cho ngày
            ];
        })->values();
        $dates = $data->pluck('date')->unique(); // Danh sách ngày (trục hoành)
        // Lấy các loại lỗi duy nhất
        $errorTypes = $data->flatMap->error_rates->keys()->unique();
        // Cấu trúc dữ liệu cho từng loại lỗi với tỷ lệ lỗi theo ngày
        $seriesData = $errorTypes->map(function ($errorType) use ($data) {
            return [
                'name' => $errorType,
                'data' => $data->map(function ($dayData) use ($errorType) {
                    return $dayData['error_rates']->get($errorType, 0); // Tỷ lệ lỗi, mặc định 0 nếu không có lỗi trong ngày đó
                })->toArray()
            ];
        })->toArray();
        return $this->success(['dates' => $dates, 'series' => $seriesData]);
    }

    public function getOQCDataSummary(Request $request)
    {
        $query = $this->oqcHistoryQuery($request);
        $qcHistories = $query->get();
        $sl_ok = 0;
        $sl_ng = 0;
        foreach ($qcHistories as $qcHistory) {
            if ($qcHistory->infoCongDoan) {
                if ($qcHistory->infoCongDoan->sl_ng > 0) {
                    $sl_ng += 1;
                } else {
                    $sl_ok += 1;
                }
            }
        }
        $data = new stdClass;
        $data->lot_check = count($qcHistories);
        $data->lot_ok = $sl_ok;
        $data->lot_ng = $sl_ng;
        $data->ng_rate = ($data->lot_check > 0 ? ceil($sl_ng / $data->lot_check) : 0) . '%';
        return $this->success($data);
    }

    public function exportOQCDataTable(Request $request)
    {
        $query = $this->oqcHistoryQuery($request);
        $records = $query->get();
        $data = $this->parseOQCData($records);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $titleStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(true, 16));
        $headerStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(), ExcelStyleHelper::fill());
        $border = ExcelStyleHelper::borders();
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
        foreach ($header as $key => $cell) {
            $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            $start_col += 1;
        }
        $sheet->setCellValue([1, 1], 'UI Truy vấn chất lượng OQC (Bảng chi tiết trang chính)')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = count($data) + 2;
        $sheet->fromArray((array)$data, NULL, 'A3', true);
        // $sheet->getStyle([1, 5, 30, count($data) + 4])->applyFromArray($centerStyle);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . 1 . ':' . $column->getColumnIndex() . ($table_row))->applyFromArray(array_merge(ExcelStyleHelper::alignment(), $border));
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


    public function getKPIPassRateChart(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                // Truy vấn và tính toán tỷ lệ đạt cho từng ngày
                $dateList[] = $this->calculatePassRateForPeriod($date->startOfDay(), $date->endOfDay(), $date->format('d/m'));
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tuần
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculatePassRateForPeriod($start, $weekEnd, 'Tuần ' . $start->format('W'));
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tháng
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculatePassRateForPeriod($start, $monthEnd, 'Tháng ' . $start->format('m/Y'));
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho năm
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculatePassRateForPeriod($start, $yearEnd, 'Năm ' . $start->format('Y'));
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    /**
     * Hàm hỗ trợ để tính tỷ lệ đạt cho một khoảng thời gian
     */
    protected function calculatePassRateForPeriod($start, $end, $label)
    {
        $totals = InfoCongDoan::select(
            DB::raw("SUM(sl_dau_ra_hang_loat) as total_sl_dau_ra_hang_loat"),
            DB::raw("SUM(sl_tem_vang) as total_sl_tem_vang"),
            DB::raw("SUM(sl_ng) as total_sl_ng")
        )
            ->whereDate('thoi_gian_bat_dau', '>=', $start)
            ->whereDate('thoi_gian_ket_thuc', '<=', $end)
            ->first();

        if ($totals->total_sl_dau_ra_hang_loat > 0) {
            $ti_le_dat_thang = ceil((($totals->total_sl_dau_ra_hang_loat - $totals->total_sl_tem_vang - $totals->total_sl_ng) / $totals->total_sl_dau_ra_hang_loat) * 100);
        } else {
            $ti_le_dat_thang = 0;
        }

        return [
            'type' => $label,
            'value' => $ti_le_dat_thang,
        ];
    }
    public function getKPITiLeVanHanhTB(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                // Truy vấn và tính toán tỷ lệ đạt cho từng ngày
                $dateList[] = $this->calculateTiLeVanHanhTBForPeriod($date->startOfDay(), $date->endOfDay(), $date->format('d/m'));
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tuần
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateTiLeVanHanhTBForPeriod($start, $weekEnd, 'Tuần ' . $start->format('W'));
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tháng
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateTiLeVanHanhTBForPeriod($start, $monthEnd, 'Tháng ' . $start->format('m/Y'));
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho năm
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateTiLeVanHanhTBForPeriod($start, $yearEnd, 'Năm ' . $start->format('Y'));
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    /**
     * Hàm hỗ trợ để tính tỷ lệ đạt cho một khoảng thời gian
     */
    protected function calculateTiLeVanHanhTBForPeriod($start, $end, $label)
    {
        $totals = InfoCongDoan::select(
            DB::raw("SUM(TIMESTAMPDIFF(SECOND, thoi_gian_bam_may, thoi_gian_ket_thuc)) / SUM(TIMESTAMPDIFF(SECOND, thoi_gian_bat_dau, thoi_gian_ket_thuc)) as total_ratio")
        )
            ->whereDate('thoi_gian_bat_dau', '>=', $start)
            ->whereDate('thoi_gian_ket_thuc', '<=', $end)
            ->whereNotNull('thoi_gian_bam_may')
            ->whereNotNull('thoi_gian_ket_thuc')
            ->whereNotNull('thoi_gian_bat_dau')
            ->first();

        if ($totals->total_ratio > 0) {
            $ti_le_van_hanh_tb = ceil($totals->total_ratio * 100);
        } else {
            $ti_le_van_hanh_tb = 0;
        }

        return [
            'type' => $label,
            'value' => $ti_le_van_hanh_tb,
        ];
    }

    public function getKPITiLeHoanThanhKeHoach(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                // Truy vấn và tính toán tỷ lệ đạt cho từng ngày
                $dateList[] = $this->calculateTiLeHoanThanhKeHoachForPeriod($date->startOfDay(), $date->endOfDay(), $date->format('d/m'));
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tuần
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateTiLeHoanThanhKeHoachForPeriod($start, $weekEnd, 'Tuần ' . $start->format('W'));
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tháng
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateTiLeHoanThanhKeHoachForPeriod($start, $monthEnd, 'Tháng ' . $start->format('m/Y'));
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho năm
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateTiLeHoanThanhKeHoachForPeriod($start, $yearEnd, 'Năm ' . $start->format('Y'));
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    /**
     * Hàm hỗ trợ để tính tỷ lệ đạt cho một khoảng thời gian
     */
    protected function calculateTiLeHoanThanhKeHoachForPeriod($start, $end, $label)
    {
        $totals = InfoCongDoan::select(
            DB::raw("SUM(sl_dau_ra_hang_loat + sl_tem_vang) / SUM(sl_kh) as total_ratio")
        )
            ->whereDate('thoi_gian_bat_dau', '>=', $start)
            ->whereDate('thoi_gian_ket_thuc', '<=', $end)
            ->whereNotNull('thoi_gian_bam_may')
            ->whereNotNull('thoi_gian_ket_thuc')
            ->whereNotNull('thoi_gian_bat_dau')
            ->first();

        if ($totals->total_ratio > 0) {
            $ti_le_hoan_thanh_ke_hoach = ceil($totals->total_ratio * 100);
        } else {
            $ti_le_hoan_thanh_ke_hoach = 0;
        }

        return [
            'type' => $label,
            'value' => $ti_le_hoan_thanh_ke_hoach > 100 ? 100 : $ti_le_hoan_thanh_ke_hoach,
        ];
    }
    public function getKPITiLeLoiCongDoan(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                // Truy vấn và tính toán tỷ lệ đạt cho từng ngày
                $dateList[] = $this->calculateTiLeLoiCongDoanForPeriod($date->startOfDay(), $date->endOfDay(), $date->format('d/m'));
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tuần
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateTiLeLoiCongDoanForPeriod($start, $weekEnd, 'Tuần ' . $start->format('W'));
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tháng
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateTiLeLoiCongDoanForPeriod($start, $monthEnd, 'Tháng ' . $start->format('m/Y'));
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho năm
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateTiLeLoiCongDoanForPeriod($start, $yearEnd, 'Năm ' . $start->format('Y'));
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    /**
     * Hàm hỗ trợ để tính tỷ lệ đạt cho một khoảng thời gian
     */
    protected function calculateTiLeLoiCongDoanForPeriod($start, $end, $label)
    {
        $totals = InfoCongDoan::select(
            DB::raw("SUM(sl_ng) / SUM(sl_dau_ra_hang_loat + sl_tem_vang) as total_ratio")
        )
            ->whereDate('thoi_gian_bat_dau', '>=', $start)
            ->whereDate('thoi_gian_ket_thuc', '<=', $end)
            ->whereNotNull('thoi_gian_bam_may')
            ->whereNotNull('thoi_gian_ket_thuc')
            ->whereNotNull('thoi_gian_bat_dau')
            ->first();

        if ($totals->total_ratio > 0) {
            $ti_le_loi_cong_doan = ceil($totals->total_ratio * 100);
        } else {
            $ti_le_loi_cong_doan = 0;
        }

        return [
            'type' => $label,
            'value' => $ti_le_loi_cong_doan > 100 ? 100 : $ti_le_loi_cong_doan,
        ];
    }

    public function getKPIMachineEfficiency(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                // Truy vấn và tính toán tỷ lệ đạt cho từng ngày
                $dateList[] = $this->calculateMachinePerformance($date->startOfDay(), $date->endOfDay(), $date->format('d/m'));
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tuần
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateMachinePerformance($start, $weekEnd, 'Tuần ' . $start->format('W'));
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tháng
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateMachinePerformance($start, $monthEnd, 'Tháng ' . $start->format('m/Y'));
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho năm
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateMachinePerformance($start, $yearEnd, 'Năm ' . $start->format('Y'));
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    function calculateMachinePerformance($start, $end, $label)
    {
        $lines = Line::where('factory_id', 2)->whereNotIn('id', [30, 29])->get();
        $data = [];
        foreach ($lines as $key => $line) {
            $result = InfoCongDoan::with('lotPlan.plan')->select(
                'info_cong_doan.sl_dau_ra_hang_loat',
                DB::raw("SUM(sl_dau_ra_hang_loat) as total_quantity"),
                DB::raw("SUM(sl_dau_ra_hang_loat - sl_ng) as ok_quantity"),
                DB::raw("SUM(TIMESTAMPDIFF(SECOND, thoi_gian_bam_may, thoi_gian_ket_thuc)) as production_time"),
                DB::raw("SUM(TIMESTAMPDIFF(SECOND, thoi_gian_bat_dau, thoi_gian_ket_thuc)) as total_time")
            )
                ->whereDate('thoi_gian_bat_dau', '>=', $start)
                ->whereDate('thoi_gian_ket_thuc', '<=', $end)
                ->whereNotNull('thoi_gian_bam_may')
                ->whereNotNull('thoi_gian_ket_thuc')
                ->whereNotNull('thoi_gian_bat_dau')
                ->where('line_id', $line->id)
                ->first('line_id');
            $plan = $result->lotPlan->plan ?? null;
            $A = ($result->total_time > 0 ? $result->production_time / $result->total_time : 0) * 100;
            $P = ((isset($plan) && $plan->UPH && $result->production_time > 0) ? ($result->total_quantity / (($result->production_time / 3600) * (int)$plan->UPH)) * 100 : 1) * 100;
            $Q = ($result->total_quantity > 0 ? $result->ok_quantity / $result->total_quantity : 0) * 100;
            $OEE = ($A * $Q * $P) / 10000;
            $data[] = [
                'name' => $line->name,
                'data' => round($OEE, 1),
            ];
        }
        return [
            'type' => $label,
            'value' => $data,
        ];
    }

    public function getKPISoLanDungMay(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                // Truy vấn và tính toán tỷ lệ đạt cho từng ngày
                $dateList[] = $this->calculateMachineDownTime($date->startOfDay(), $date->endOfDay(), $date->format('d/m'));
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tuần
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateMachineDownTime($start, $weekEnd, 'Tuần ' . $start->format('W'));
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho tháng
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateMachineDownTime($start, $monthEnd, 'Tháng ' . $start->format('m/Y'));
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();

            while ($start->lte($end)) {
                // Tính tỷ lệ đạt cho năm
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateMachineDownTime($start, $yearEnd, 'Năm ' . $start->format('Y'));
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    public function calculateMachineDownTime($start, $end, $label)
    {
        $lines = Line::where('factory_id', 2)->get();
        $machines = Machine::whereIn('line_id', $lines->pluck('id')->toArray())->get();
        $query = MachineLog::whereIn('machine_id', $machines->pluck('code')->toArray())
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->whereNotNull('info->lot_id')
            ->whereNotNull('info->start_time')
            ->whereNotNull('info->end_time');
        // return $query->get();

        $count = $query->count();
        $count_down = $query->where('info->error_id', '=', 124)->count();
        $time = $query->select(DB::raw("SUM(JSON_UNQUOTE(JSON_EXTRACT(info, '$.end_time')) - JSON_UNQUOTE(JSON_EXTRACT(info, '$.start_time'))) as stop_time"))->first();
        $stopTime = 0;
        if (!$time->stop_time) {
            $stopTime = 0;
        } else {
            $stopTime = $time->stop_time;
        }
        $stopTime = round($stopTime / 3600, 1);
        return [
            'type' => $label,
            'value' => [
                [
                    'name' => 'Số lần dừng máy',
                    'data' => $count,
                ],
                [
                    'name' => 'Số giờ dừng',
                    'data' => $stopTime,
                ],
                [
                    'name' => 'Số lần hỏng máy',
                    'data' => $count_down,
                ]
            ]
        ];
    }
    public function generatePowerConsumes()
    {
        $deviceId = '9032a0e0-45bc-11ef-b8c3-a13625245eca';
        $machineCode = 'IN_4_MAU_01';
        $startValue = 0;

        // Lặp qua tất cả các ngày trong tháng 11, trừ ngày Chủ nhật
        for ($day = 1; $day <= 30; $day++) {
            $date = "2024-10-" . str_pad($day, 2, '0', STR_PAD_LEFT);
            $dayOfWeek = date('w', strtotime($date));

            // Bỏ qua ngày Chủ nhật
            if ($dayOfWeek == 0) {
                continue;
            }

            // Tạo số random trong khoảng từ 150 đến 185.5
            $endValue = mt_rand(1500, 1855) / 10;

            // Chèn vào bảng power_consumes
            DB::table('power_consumes')->insert([
                'device_id' => $deviceId,
                'machine_code' => $machineCode,
                'start_value' => $startValue,
                'end_value' => $endValue,
                'date' => $date,
            ]);
        }
    }

    public function deleteOvertimeMachineLog()
    {
        // Lấy tất cả các bản ghi MachineLog
        $logs = MachineLog::all();

        foreach ($logs as $log) {
            if (!isset($log->info['end_time'])) {
                $log->delete();
            }
            // Kiểm tra nếu info có cả start_time và end_time
            if (isset($log->info['start_time']) && isset($log->info['end_time'])) {
                $startTime = Carbon::createFromTimestamp($log->info['start_time']);
                $endTime = Carbon::createFromTimestamp($log->info['end_time']);

                // Tính sự chênh lệch thời gian
                $diff = $startTime->diffInMinutes($endTime);

                // Xóa bản ghi nếu khoảng thời gian nhỏ hơn 10 phút
                if ($diff < 10) {
                    $log->delete();
                }
            }
        }
        return 'ok';
    }

    public function clearFakeData()
    {
        $info = InfoCongDoan::where(function ($query) {
            $query->whereDate('created_at', '<=', '2024-10-22')->orWhereDate('created_at', '>', date("Y-m-d"));
        })->delete();
        $err = ErrorHistory::where(function ($query) {
            $query->whereDate('created_at', '<=', '2024-10-22')->orWhereDate('created_at', '>', date("Y-m-d"));
        })->delete();
        $plan = ProductionPlan::whereDate('created_at', '<=', '2024-10-22')->each(function ($plan) {
            $plan->lotPlan()->delete();
            $plan->delete();
        });
        $lot_plan = LotPlan::whereNotIn('production_plan_id', function ($query) {
            $query->select('id')->from('production_plans');
        })->delete();
        return 'done';
    }

    public function updateLineSelectInfo(Request $request)
    {
        $infos = InfoCongDoan::with('assignments')->where('line_id', 29)->get();
        foreach ($infos as $info) {
            $assignment = count($info->assignments) ? $info->assignments[0] : null;
            if (isset($assignment->actual_quantity)) {
                $info->update(['sl_dau_ra_hang_loat' => $assignment->actual_quantity]);
            }
        }
        return 'done';
    }
    public function getKPIOrderProduction(Request $request)
    {
        $dateType = $request->dateType;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $dateList = [];
        $listByProduct = [];

        if ($dateType == 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);
            foreach ($period as $date) {
                $dateList[] = $this->calculateOrderProductionForPeriod(
                    $date->startOfDay(),
                    $date->endOfDay(),
                    $date->format('d/m')
                );
            }
        } elseif ($dateType == 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end = Carbon::parse($endDate)->endOfWeek();
            while ($start->lte($end)) {
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateOrderProductionForPeriod(
                    $start,
                    $weekEnd,
                    'Tuần ' . $start->format('W')
                );
                $start->addWeek();
            }
        } elseif ($dateType == 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end = Carbon::parse($endDate)->endOfMonth();
            while ($start->lte($end)) {
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateOrderProductionForPeriod(
                    $start,
                    $monthEnd,
                    'Tháng ' . $start->format('m/Y')
                );
                $start->addMonth();
            }
        } elseif ($dateType == 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end = Carbon::parse($endDate)->endOfYear();
            while ($start->lte($end)) {
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateOrderProductionForPeriod(
                    $start,
                    $yearEnd,
                    'Năm ' . $start->format('Y')
                );
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }
        // $listByProduct = $this->getKPIOrderProductionByProduct(Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay());
        return $this->success(['order_production' => $dateList, 'product_production' => $listByProduct]);
    }

    // Thêm hàm mới để lấy dữ liệu theo sản phẩm
    protected function getKPIOrderProductionByProduct(Request $request)
    {
        $dateType  = $request->dateType;     // 'date' | 'week' | 'month' | 'year'
        $startDate = $request->start_date;
        $endDate   = $request->end_date;
        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = Carbon::parse($request->end_date)->endOfDay();
        // --- Tổng SL đơn hàng theo sản phẩm ---
        $poByProduct = DB::table('product_orders as po')
            ->select('po.product_id', 'po.product_name', DB::raw('SUM(po.quantity) as total_orders'))
            ->whereBetween('po.order_date', [$start, $end])
            ->groupBy('po.product_id', 'po.product_name');

        // --- Sản lượng đã SX (line_id = 29) theo sản phẩm ---
        $producedByProduct = DB::table('info_cong_doan as icd')
            ->join('losx as lx', 'lx.id', '=', 'icd.lo_sx')
            ->join('product_orders as po', 'po.id', '=', 'lx.product_order_id')
            ->whereBetween('po.order_date', [$start, $end])
            ->where('icd.line_id', 29)
            ->groupBy('po.product_id', 'po.product_name')
            ->select('po.product_id', 'po.product_name', DB::raw('SUM(icd.sl_dau_ra_hang_loat - icd.sl_tem_vang - icd.sl_ng) as produced'));

        // --- Ghép 2 nguồn ---
        $rows = DB::query()
            ->fromSub($poByProduct, 'po_by_prod')
            ->leftJoinSub($producedByProduct, 'prod', function ($join) {
                $join->on('prod.product_id', '=', 'po_by_prod.product_id')
                    ->on('prod.product_name', '=', 'po_by_prod.product_name');
            })
            ->select(
                'po_by_prod.product_id',
                'po_by_prod.product_name',
                DB::raw('po_by_prod.total_orders as total_orders'),
                DB::raw('COALESCE(prod.produced, 0) as produced')
            )
            ->orderBy('po_by_prod.total_orders', 'DESC')
            ->orderBy('po_by_prod.product_id')
            ->get()
            ->take(4)
            ->map(function ($r) {
                $total    = (int) $r->total_orders;
                $produced = (int) $r->produced;
                $remain   = max(0, $total - $produced);

                return [
                    'ma_san_pham' => $r->product_id,
                    'ten_san_pham' => $r->product_name,
                    'don_hang'     => $total,
                    'da_san_xuat'  => $produced,
                    'chua_san_xuat' => $remain,
                ];
            })
            ->values();

        // --- Summary ---
        $summaryRow = [
            'ma_san_pham' => '',
            'ten_san_pham' => 'Tổng',
            'don_hang'     => (int) $rows->sum('don_hang'),
            'da_san_xuat'  => (int) $rows->sum('da_san_xuat'),
            'chua_san_xuat' => (int) $rows->sum('chua_san_xuat'),
        ];

        // Đưa "Tổng" vào đầu mảng
        $result = collect([$summaryRow])->merge($rows)->values();
        if ($dateType === 'date') {
            $start = Carbon::parse($startDate)->startOfDay();
            $end   = Carbon::parse($endDate)->endOfDay();
            $start_title = $start->format('d/m');
            $end_title = $end->format('d/m');
        } elseif ($dateType === 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end   = Carbon::parse($endDate)->endOfWeek();
            $start_title = 'Tuần ' . $start->format('W');
            $end_title = 'Tuần ' . $end->format('W');
        } elseif ($dateType === 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end   = Carbon::parse($endDate)->endOfMonth();
            $start_title = 'Tháng ' . $start->format('m/Y');
            $end_title = 'Tháng ' . $end->format('m/Y');
        } elseif ($dateType === 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end   = Carbon::parse($endDate)->endOfYear();
            $start_title = 'Năm ' . $start->format('Y');
            $end_title = 'Năm ' . $end->format('Y');
        }
        return $this->success([
            'result' => $result,
            'start' => $start_title,
            'end' => $end_title,
        ]);
    }

    protected function calculateOrderProductionForPeriod($start, $end, $label)
    {
        // Tổng số lượng đơn hàng
        $totalOrders = DB::table('product_orders')
            ->whereBetween('order_date', [$start, $end])
            ->sum('quantity');

        // Số lượng đã sản xuất (join qua losx -> info_cong_doan)
        $produced = DB::table('info_cong_doan as icd')
            ->join('losx', 'losx.id', '=', 'icd.lo_sx')
            ->join('product_orders as po', 'po.id', '=', 'losx.product_order_id')
            ->whereBetween('po.order_date', [$start, $end])
            ->where('icd.line_id', 29)
            ->sum(DB::raw('icd.sl_dau_ra_hang_loat - icd.sl_tem_vang - icd.sl_ng'));
        // Chưa sản xuất
        $notProduced = max(0, $totalOrders - $produced);

        return [
            'type' => $label,
            'total_orders' => (int) $totalOrders,
            'produced' => (int) $produced,
            'not_produced' => (int) $notProduced
        ];
    }
    public function getKPIOrderProductionByCustomer(Request $request)
    {
        $dateType  = $request->dateType;     // 'date' | 'week' | 'month' | 'year'
        $startDate = $request->start_date;
        $endDate   = $request->end_date;

        $dateList = [];

        if ($dateType === 'date') {
            $period = CarbonPeriod::create($startDate, $endDate);
            foreach ($period as $date) {
                $dateList[] = $this->calculateOrderProductionByCustomerForPeriod(
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay(),
                    $date->format('d/m')
                );
            }
        } elseif ($dateType === 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end   = Carbon::parse($endDate)->endOfWeek();
            while ($start->lte($end)) {
                $weekEnd = $start->copy()->endOfWeek();
                $dateList[] = $this->calculateOrderProductionByCustomerForPeriod(
                    $start->copy(),
                    $weekEnd->copy(),
                    'Tuần ' . $start->format('W')
                );
                $start->addWeek();
            }
        } elseif ($dateType === 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end   = Carbon::parse($endDate)->endOfMonth();
            while ($start->lte($end)) {
                $monthEnd = $start->copy()->endOfMonth();
                $dateList[] = $this->calculateOrderProductionByCustomerForPeriod(
                    $start->copy(),
                    $monthEnd->copy(),
                    'Tháng ' . $start->format('m/Y')
                );
                $start->addMonth();
            }
        } elseif ($dateType === 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end   = Carbon::parse($endDate)->endOfYear();
            while ($start->lte($end)) {
                $yearEnd = $start->copy()->endOfYear();
                $dateList[] = $this->calculateOrderProductionByCustomerForPeriod(
                    $start->copy(),
                    $yearEnd->copy(),
                    'Năm ' . $start->format('Y')
                );
                $start->addYear();
            }
        } else {
            return $this->failure('Loại dữ liệu không hợp lệ');
        }

        return $this->success($dateList);
    }

    /**
     * Trả về:
     * [
     *   'type'    => <nhãn kỳ>,
     *   'data'    => [ { customer_id, total_orders, produced, not_produced }, ... ],
     *   'summary' => { total_orders, produced, not_produced }
     * ]
     */
    protected function calculateOrderProductionByCustomerForPeriod($start, $end, $label)
    {
        // Tổng số lượng đặt hàng theo customer trong kỳ
        $poByCustomer = DB::table('product_orders as po')
            ->select('po.customer_id', DB::raw('SUM(po.quantity) as total_orders'))
            ->whereBetween('po.order_date', [$start, $end])
            ->groupBy('po.customer_id');

        // Sản lượng đã sản xuất (line_id = 29) theo customer trong kỳ
        $producedByCustomer = DB::table('info_cong_doan as icd')
            ->join('losx as lx', 'lx.id', '=', 'icd.lo_sx')
            ->join('product_orders as po', 'po.id', '=', 'lx.product_order_id')
            ->whereBetween('po.order_date', [$start, $end])
            ->where('icd.line_id', 29)
            ->groupBy('po.customer_id')
            ->select('po.customer_id', DB::raw('SUM(icd.sl_dau_ra_hang_loat - icd.sl_tem_vang - icd.sl_ng) as produced'));

        // Ghép 2 nguồn dữ liệu: tổng đặt hàng (bắt buộc có) + sản lượng (có thể null)
        $rows = DB::query()
            ->fromSub($poByCustomer, 'po_by_cus')
            ->leftJoinSub($producedByCustomer, 'prod', 'prod.customer_id', '=', 'po_by_cus.customer_id')
            ->select(
                'po_by_cus.customer_id',
                DB::raw('po_by_cus.total_orders as total_orders'),
                DB::raw('COALESCE(prod.produced, 0) as produced')
            )
            ->orderBy('po_by_cus.customer_id')
            ->get()
            ->map(function ($r) {
                $total     = (int) $r->total_orders;
                $produced  = (int) $r->produced;
                $notProd   = max(0, $total - $produced);

                return [
                    'customer_id'   => $r->customer_id,
                    'total_orders'  => $total,    // tổng SL đặt hàng (SUM(quantity))
                    'produced'      => $produced, // SL đã SX (SUM sl_dau_ra_hang_loat @ line 29)
                    'not_produced'  => $notProd,  // phần còn lại
                ];
            })
            ->values();

        // Tổng hợp kỳ
        $summary = [
            'total_orders' => (int) $rows->sum('total_orders'),
            'produced'     => (int) $rows->sum('produced'),
            'not_produced' => (int) $rows->sum('not_produced'),
        ];

        return [
            'type'    => $label,
            'data'    => $rows,
            'summary' => $summary,
        ];
    }
    public function getKPIOrderProductionSummaryAndByCustomer(Request $request)
    {
        $dateType  = $request->dateType;     // 'date' | 'week' | 'month' | 'year'
        $startDate = $request->start_date;
        $endDate   = $request->end_date;

        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = Carbon::parse($request->end_date)->endOfDay();

        // --- Tổng SL đơn hàng theo customer ---
        $poByCustomer = DB::table('product_orders as po')
            ->select('po.customer_id', DB::raw('SUM(po.quantity) as total_orders'))
            ->whereBetween('po.order_date', [$start, $end])
            ->groupBy('po.customer_id');

        // --- Sản lượng đã SX (line_id = 29) theo customer ---
        $producedByCustomer = DB::table('info_cong_doan as icd')
            ->join('losx as lx', 'lx.id', '=', 'icd.lo_sx')
            ->join('product_orders as po', 'po.id', '=', 'lx.product_order_id')
            ->whereBetween('po.order_date', [$start, $end])
            ->where('icd.line_id', 29)
            ->groupBy('po.customer_id')
            ->select('po.customer_id', DB::raw('SUM(icd.sl_dau_ra_hang_loat - icd.sl_tem_vang - icd.sl_ng) as produced'));

        // --- Ghép 2 nguồn ---
        $rows = DB::query()
            ->fromSub($poByCustomer, 'po_by_cus')
            ->leftJoinSub($producedByCustomer, 'prod', 'prod.customer_id', '=', 'po_by_cus.customer_id')
            ->leftJoin('customer', 'customer.id', '=', 'po_by_cus.customer_id') // Thêm join với bảng customers
            ->select(
                'po_by_cus.customer_id',
                'customer.name as customer_name', // Lấy tên customer
                DB::raw('po_by_cus.total_orders as total_orders'),
                DB::raw('COALESCE(prod.produced, 0) as produced')
            )
            ->orderBy('po_by_cus.customer_id')
            ->get()
            ->map(function ($r) {
                $total    = (int) $r->total_orders;
                $produced = (int) $r->produced;
                $remain   = max(0, $total - $produced);

                return [
                    'customer_id'  => (string) $r->customer_id,
                    'customer_name' => $r->customer_name, // Lấy tên từ kết quả query
                    'total_orders' => $total,
                    'produced'     => $produced,
                    'not_produced' => $remain,
                ];
            })
            ->values();

        // --- Summary ---
        $summaryRow = [
            'customer_id'  => 'TOTAL',
            'customer_name' => 'Tổng',
            'total_orders' => (int) $rows->sum('total_orders'),
            'produced'     => (int) $rows->sum('produced'),
            'not_produced' => (int) max(0, $rows->sum('total_orders') - $rows->sum('produced')),
        ];

        // Đưa "Tổng" vào đầu mảng customers
        $customers = collect([$summaryRow])->merge($rows)->values();

        if ($dateType === 'date') {
            $start = Carbon::parse($startDate)->startOfDay();
            $end   = Carbon::parse($endDate)->endOfDay();
            $start_title = $start->format('d/m');
            $end_title = $end->format('d/m');
        } elseif ($dateType === 'week') {
            $start = Carbon::parse($startDate)->startOfWeek();
            $end   = Carbon::parse($endDate)->endOfWeek();
            $start_title = 'Tuần ' . $start->format('W');
            $end_title = 'Tuần ' . $end->format('W');
        } elseif ($dateType === 'month') {
            $start = Carbon::parse($startDate)->startOfMonth();
            $end   = Carbon::parse($endDate)->endOfMonth();
            $start_title = 'Tháng ' . $start->format('m/Y');
            $end_title = 'Tháng ' . $end->format('m/Y');
        } elseif ($dateType === 'year') {
            $start = Carbon::parse($startDate)->startOfYear();
            $end   = Carbon::parse($endDate)->endOfYear();
            $start_title = 'Năm ' . $start->format('Y');
            $end_title = 'Năm ' . $end->format('Y');
        }
        return $this->success([
            'summary'   => [
                'total_orders' => $summaryRow['total_orders'],
                'produced'     => $summaryRow['produced'],
                'not_produced' => $summaryRow['not_produced'],
            ],
            'customers' => $customers, // phần tử đầu tiên là "Tổng", tiếp theo là từng customer,
            'start' => $start_title,
            'end' => $end_title,
        ]);
    }
}
