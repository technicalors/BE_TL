<?php

namespace App\Admin\Controllers;

use App\Helpers\ExcelStyleHelper;
use App\Helpers\QueryHelper;
use App\Http\Controllers\Controller;
use App\Imports\InfoCongDoanImport;
use App\Imports\WarehouseLocationImport;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\ErrorHistory;
use App\Models\ErrorMachine;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Inventory;
use App\Models\Line;
use App\Models\LineInventories;
use App\Models\Losx;
use App\Models\Lot;
use App\Models\LotPlan;
use App\Models\Machine;
use App\Models\MachineLog;
use App\Models\MachinePriorityOrder;
use App\Models\NumberMachineOrder;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\ProductOrder;
use App\Models\QCHistory;
use App\Models\Shift;
use App\Models\Spec;
use App\Models\TestCriteria;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Borders;
use PhpOffice\PhpSpreadsheet\Style\Border;

class Phase2UIApiController extends Controller
{
    use API;
    public function getTreeSelect(Request $request)
    {
        $factories = Factory::where('id', 2)->get();
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
            $overall["sl_dau_ra_thuc_te_ok"] += $item->sl_dau_ra_hang_loat - ($item->sl_tem_vang + $item->sl_ng);
            $sl_thuc_te += $item->sl_dau_ra_hang_loat - $item->sl_ng;
            $overall["sl_tem_vang"] += $item->sl_tem_vang;
            $overall["sl_ng"] += $item->sl_ng;
            $overall['sl_dau_ra_kh'] += $item->lotPlan->quantity ?? 0;
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

            // $info = $item->lot->log ? $item->lot->log->info : [];
            // $line_key = Str::slug($item->line->name);
            $errors = [];
            $thoi_gian_kiem_tra = '';
            $sl_ng_pqc = 0;
            $sl_ng_sxkt = 0;
            $user_pqc = '';
            $user_sxkt = '';
            foreach ($item->qcHistory->errorHistories ?? [] as $key => $errorHistory) {
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
            $tm = [
                "ngay_sx" => date('d/m/Y H:i:s', strtotime($item->created_at)),
                'ca_sx' => $ca_sx,
                'xuong' => 'Giấy',
                "cong_doan" => $item->line->name,
                "machine" => $item->machine_code ?? "",
                "machine_id" => $item->machine_code ?? "",
                "khach_hang" => $item->product->customer->name ?? "",
                "ten_san_pham" => $item->product->name ?? '',
                "product_id" => $item->product_id ?? '',
                "material_id" => $item->product->material_id ?? '',
                "lo_sx" => $item->lo_sx,
                "lot_id" => $item->lot_id,
                "thoi_gian_bat_dau_kh" => $item->lotPlan ? date('d/m/Y H:i:s', strtotime($item->lotPlan->start_time)) : '',
                "thoi_gian_ket_thuc_kh" => $item->lotPlan ? date('d/m/Y H:i:s', strtotime($item->lotPlan->end_time)) : '',
                "sl_dau_vao_kh" => $item->lotPlan->quantity ?? 0,
                "sl_dau_ra_kh" => $item->lotPlan->quantity ?? 0,
                "thoi_gian_bat_dau" => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : '-',
                "thoi_gian_bam_may" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "thoi_gian_ket_thuc" => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : '-',
                "thoi_gian_chay_san_luong" => number_format($d / 60, 2),
                "sl_ng" => $sl_ng_pqc + $sl_ng_sxkt,
                "sl_tem_vang" => $item->sl_tem_vang,
                "sl_dau_ra_ok" => $item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang,
                "ti_le_ng" => number_format($item->sl_dau_ra_hang_loat > 0 ? ($item->sl_ng / $item->sl_dau_ra_hang_loat) : 0, 2) * 100,
                "sl_dau_ra_hang_loat" => $item->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_vao_hang_loat" => $item->sl_dau_vao_hang_loat ?? 0,
                "sl_dau_ra_chay_thu" => $item->sl_dau_ra_chay_thu ?? 0,
                "sl_dau_vao_chay_thu" => $item->sl_dau_vao_chay_thu ?? 0,
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
            $query->where('product_id',$request->product_id);
        }
        if (isset($request->ten_sp)) {
            $query->where('product_id', $request->ten_sp);
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
        if (isset($request->lot_id)) {
            $query->where('lot_id', 'like', "%$request->lot_id%");
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
            $thoi_gian_kiem_tra = '';
            $sl_ng_pqc = 0;
            $sl_ng_sxkt = 0;
            $user_pqc = '';
            $user_sxkt = '';
            foreach ($item->qcHistory->errorHistories ?? [] as $key => $errorHistory) {
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
                "thoi_gian_bat_dau_kh" => $item->lotPlan ? date('d/m/Y H:i:s', strtotime($item->lotPlan->start_time)) : '',
                "thoi_gian_ket_thuc_kh" => $item->lotPlan ? date('d/m/Y H:i:s', strtotime($item->lotPlan->end_time)) : '',
                "sl_dau_vao_kh" => $item->lotPlan->quantity ?? 0,
                "sl_dau_ra_kh" => $item->lotPlan->quantity ?? 0,
                "thoi_gian_bat_dau_vao_hang" => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : '-',
                "thoi_gian_ket_thuc_vao_hang" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "sl_dau_vao_chay_thu" => $item->sl_dau_vao_chay_thu ?? 0,
                "sl_dau_ra_chay_thu" => $item->sl_dau_ra_chay_thu ?? 0,
                "thoi_gian_bat_dau_san_luong" => $item->thoi_gian_bam_may ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bam_may)) : '-',
                "thoi_gian_ket_thuc_san_luong" => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : '-',
                "sl_dau_vao_hang_loat" => $item->sl_dau_vao_hang_loat ?? 0,
                "sl_dau_ra_hang_loat" => $item->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_ra_ok" => $item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang,
                "sl_tem_vang" => $item->sl_tem_vang,
                "sl_ng" => $sl_ng_pqc + $sl_ng_sxkt,
                "chenh_lech" => $item->sl_dau_vao_hang_loat - $item->sl_dau_ra_hang_loat,
                "ty_le_dat" => $item->sl_dau_ra_hang_loat > 0 ? number_format(($item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang) / $item->sl_dau_ra_hang_loat) : '-',
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
                'Sản xuất sản lượng' => ['Thời gian bắt đầu sản xuất sản lượng', 'Thời gian kết thúc sản xuất sản lượng', 'Số lượng đầu vào thực tế', 'Số lượng đầu ra thực tế', 'Số lượng đầu ra OK', 'Số lượng tem vàng', 'Số lượng NG']
            ],
            'Chênh lệch',
            "tỷ lệ đạt",
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
        $sl_kh = (int)$val->sum(function ($item) {
            return $item->lotPlan->quantity;
        });
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
            $end = Carbon::parse($item->lotPlan->start_time);
            $lead_time += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->lotPlan->start_time);
            $end = Carbon::parse($item->lotPlan->end_time);
            $tg_sx_kh += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx_in_hours = $start->diffInHours($end); // Tính tổng phút
            $sl_muc_tieu += ($tg_sx_in_hours / 3600) * ($item->lotPlan->plan->UPH ?? 0);
        });
        $ty_le_ng = ($sl_dau_ra > 0 ? number_format($sl_ng / $sl_dau_ra, 2) * 100 : 0) . '%';
        $ty_le_hao_phi_tg = ($tg_sx > 0 ? number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0) . '%';
        $ty_le_hoan_thanh = ($sl_kh > 0 ? number_format($sl_ok / $sl_kh, 2) * 100 : 0) . '%';
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
            'so_nhan_su' => $val[0]->lotPlan->plan->nhan_luc ?? "",
        ];
        return $item;
    }

    function parseReportTable1($value, $date, $machine_code)
    {
        $sl_dau_vao = (int)$value->sum('sl_dau_vao_hang_loat');
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
            $end = Carbon::parse($item->lotPlan->start_time);
            $lead_time += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->lotPlan->start_time);
            $end = Carbon::parse($item->lotPlan->end_time);
            $tg_sx_kh += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx_in_hours = $start->diffInHours($end); // Tính tổng phút
            $sl_muc_tieu += ($tg_sx_in_hours / 3600) * ($item->lotPlan->plan->UPH ?? 0);
        });
        $ty_le_ng = ($sl_dau_ra > 0 ? number_format($sl_ng / $sl_dau_ra, 2) * 100 : 0) . '%';
        $ty_le_hao_phi_tg = ($tg_sx > 0 ? number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0) . '%';
        $A = ($tg_sx > 0 ? number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0);
        $Q = ($sl_dau_ra > 0 ? number_format($sl_ok / $sl_dau_ra, 2) * 100 : 0);
        $P = ($sl_muc_tieu > 0 ? number_format($sl_dau_ra / $sl_muc_tieu, 2) * 100 : 0);
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
            $end = Carbon::parse($item->lotPlan->start_time);
            $lead_time += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->lotPlan->start_time);
            $end = Carbon::parse($item->lotPlan->end_time);
            $tg_sx_kh += $start->diffInMinutes($end); // Tính tổng phút

            $start = Carbon::parse($item->thoi_gian_bat_dau);
            $end = Carbon::parse($item->thoi_gian_ket_thuc);
            $tg_sx_in_hours = $start->diffInHours($end); // Tính tổng phút
            $sl_muc_tieu += ($tg_sx_in_hours / 3600) * ($item->lotPlan->plan->UPH ?? 0);
        });
        $ty_le_ng = ($sl_dau_ra > 0 ? number_format($sl_ng / $sl_dau_ra, 2) * 100 : 0) . '%';
        $ty_le_hao_phi_tg = ($tg_sx > 0 ? number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0) . '%';
        $A = ($tg_sx > 0 ? number_format($tg_vao_hang / $tg_sx, 2) * 100 : 0);
        $Q = ($sl_dau_ra > 0 ? number_format($sl_ok / $sl_dau_ra, 2) * 100 : 0);
        $P = ($sl_muc_tieu > 0 ? number_format($sl_dau_ra / $sl_muc_tieu, 2) * 100 : 0);
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
        $query = InfoCongDoan::with('product', 'lotPlan')
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
        $query = QCHistory::orderBy('created_at');
        if (isset($request->date) && count($request->date) == 2) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
                ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        $query->whereHas('infoCongDoan', function ($query) use ($request) {
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

            $user_sx = CustomUser::find($qc_history->infoCongDoan->user_id ?? null);
            $user_qc = $qc_history->user;
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
                'user_sxkt' => $user_sx->name ?? "",
                'sl_ng_pqc' => $sl_ng_qc,
                'user_pqc' => $user_qc->name ?? "",
                'sl_ng' => $qc_history->infoCongDoan->sl_ng ?? 0,
                'ti_le_ng' => (isset($qc_history->infoCongDoan->sl_dau_ra_hang_loat) && $qc_history->infoCongDoan->sl_dau_ra_hang_loat > 0) ? number_format(($qc_history->infoCongDoan->sl_ng / $qc_history->infoCongDoan->sl_dau_ra_hang_loat) * 100) . "%" : "0%",
            ];
            if (!$isExport) {
                $item['ngay_sx'] = $qc_history->scanned_time ? Carbon::parse($qc_history->scanned_time)->format('d/m/Y') : "";
                $testCriteriaHistories = TestCriteriaHistory::where('q_c_history_id', $qc_history->id)->pluck('result')->toArray();
                $final_qc_result = "";
                if (count($testCriteriaHistories) === 3) {
                    if (in_array('NG', $testCriteriaHistories)) {
                        $final_qc_result = "NG";
                    } else {
                        $final_qc_result = "OK";
                    }
                }
                $item['final_qc_result'] = $final_qc_result;
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
        $query = InfoCongDoan::with('qcHistory.errorHistories.error')->where('lot_id', $request->lot_id);
        if (!empty($request->cong_doan)) {
            $query->whereHas('line', function ($q) use ($request) {
                $q->where('factory_id', 2)->where('name', $request->cong_doan);
            });
        }
        if (!empty($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        $infoCongDoan = $query->first();
        if (!$infoCongDoan) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        $qcHistory = $infoCongDoan->qcHistory;
        $errorHistories = $qcHistory->errorHistories;
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
        $lineMachineQuery = clone $query;
        $query->with('testCriteriaHistories.testCriteriaDetailHistories.testCriteriaHistory.qcHistory.infoCongDoan');
        $lineMachines = $lineMachineQuery->get()->map(function ($history) {
            return [
                'machine_code' => $history->infoCongDoan->machine_code ?? null,
                'line_id' => $history->infoCongDoan->line_id ?? null,
                'product_id' => $history->infoCongDoan->product_id ?? null,
            ];
        })->toArray();
        $lineMachines = array_map('unserialize', array_values(array_unique(array_map('serialize', $lineMachines))));
        usort($lineMachines, function ($a, $b) {
            if ($a['line_id'] === $b['line_id']) {
                if ($a['machine_code'] === $b['machine_code']) {
                    return strcmp($a['product_id'], $b['product_id']); // So sánh product_id nếu line_id và machine_code giống nhau
                }
                return strcmp($a['machine_code'], $b['machine_code']); // So sánh machine_code nếu line_id giống nhau
            }
            return $a['line_id'] <=> $b['line_id']; // So sánh line_id
        });
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet_index = 0;
        foreach ($lineMachines as $lineMachine) {
            $line = Line::find($lineMachine['line_id']);
            $machine = Machine::where('code', $lineMachine['machine_code'])->first();
            $product = Product::find($lineMachine['product_id']);
            if (!$line || !$product) {
                continue;
            }
            $sheet = $spreadsheet->getSheet($sheet_index);
            $sheet->setTitle($line->name . ($machine ? " - " . $machine->code : ''));
            $lineQcHistoriesQuery = clone $query;
            $qcHistories = $lineQcHistoriesQuery->whereHas('infoCongDoan', function ($infoQuery) use ($line, $machine, $product) {
                $infoQuery->where('line_id', $line->id)->where('machine_code', $machine->code ?? null)->where('product_id', $product->id);
            })->get();
            $infos = $this->parseQCData($qcHistories);
            $history = $qcHistories->flatMap->testCriteriaHistories->flatMap->testCriteriaDetailHistories->groupBy(function ($item) {
                return $item->testCriteriaHistory->qcHistory->infoCongDoan->lot_id;
            });
            $checked_data = [];
            $lot_id_array = [];
            $losx_array = [];
            $ngay_sx_array = [];
            $product_array = [];
            $machine_array = [];
            foreach ($infos as $info) {
                if ($info['lot_id']) {
                    if (isset($info['lot_id']) && !in_array($info['lot_id'], $lot_id_array)) $lot_id_array[] = $info['lot_id'];
                    if (isset($info['lo_sx']) && !in_array($info['lo_sx'], $losx_array)) $losx_array[] = $info['lo_sx'];
                    if (isset($info['ngay_sx']) && !in_array($info['ngay_sx'], $ngay_sx_array)) $ngay_sx_array[] = $info['ngay_sx'];
                    if (isset($info['ten_san_pham']) && !in_array($info['ten_san_pham'], $product_array)) $product_array[] = $info['ten_san_pham'];
                    if (isset($info['machine_id']) && !in_array($info['machine_id'], $machine_array)) $machine_array[] = $info['machine_id'];
                    if (isset($history[$info['lot_id']])) {
                        $checked_data[$info['lot_id']] = $history[$info['lot_id']]->mapWithKeyss(function ($e) {
                            return [$e->test_criteria_id => $e->input ?? $e->result];
                        });
                    }
                }
            }
            $transformData = $this->transformArray($checked_data);
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
            $current_tieu_chuan = null;
            $tieu_chuan_merge_start = $row_index;
            foreach ($groupTestCriteria as $so_chi_tieu => $testCriteria) {
                $start_index = $row_index; // Dòng bắt đầu của nhóm chỉ tiêu kiểm tra
                // $current_tieu_chuan = null;
                // $tieu_chuan_merge_start = $row_index;
                // Ghi cột "Chỉ tiêu kiểm tra"
                $sheet->setCellValue([1, $row_index], $so_chi_tieu);
                foreach ($testCriteria as $index => $testCriterion) {
                    $slug_hang_muc = Str::slug($testCriterion->hang_muc);
                    $spec = Spec::where("line_id", $line->id)->where('slug', $slug_hang_muc)->whereNotNull('name')->where("product_id", $product->id)->whereNotNull('value')->first();
                    // Ghi dữ liệu cột "Hạng mục kiểm tra" và "Tiêu chuẩn"
                    $sheet->setCellValue([2, $row_index], $testCriterion->hang_muc);
                    $sheet->setCellValue([3, $row_index], $spec->value ?? $testCriterion->tieu_chuan ?? null);
                    // Xử lý dữ liệu đã kiểm tra
                    if (!isset($transformData[$testCriterion->id])) {
                        foreach ($infos as $info) {
                            $data[$testCriterion->id][$info['lot_id']] = null;
                        }
                    } else {
                        $data[$testCriterion->id] = $transformData[$testCriterion->id];
                    }
                    $row_data = array_filter($data[$testCriterion->id]);
                    if (empty($row_data)) {
                        unset($data[$testCriterion->id]);
                        continue;
                    };
                    // Merge các ô trong cột "Tiêu chuẩn" (Cột 3)
                    // if ($testCriterion->tieu_chuan !== $current_tieu_chuan) {
                    //     if ($current_tieu_chuan !== null && $tieu_chuan_merge_start < $row_index - 1) {
                    //         $sheet->mergeCells([3, $tieu_chuan_merge_start, 3, $row_index - 1]);
                    //     }
                    //     $current_tieu_chuan = $testCriterion->tieu_chuan;
                    //     $tieu_chuan_merge_start = $row_index; // Đặt lại vị trí bắt đầu merge
                    // }
                    $row_index++;
                }

                // Merge các ô trong cột "Chỉ tiêu kiểm tra" (Cột 1)
                if ($start_index < $row_index - 1) {
                    $sheet->mergeCells([1, $start_index, 1, $row_index - 1]);
                }
            }
            // // Merge tiêu chuẩn cuối cùng trong nhóm
            // if ($tieu_chuan_merge_start < $row_index - 1) {
            //     $sheet->mergeCells([3, $tieu_chuan_merge_start, 3, $row_index - 1]);
            // }
            $next_rows = [
                'final_qc_result' => 'Đánh giá tổng thể kết quả kiểm tra',
                'sl_dau_ra_hang_loat' => 'Số lượng sản xuất',
                'sl_dau_ra_ok' => 'Số lượng OK',
                'sl_ng' => 'Số lượng NG',
                'sl_tem_vang' => 'Số lượng tem vàng',
                'user_sxkt' => 'Công nhân sản xuất',
                'user_pqc' => 'QC kiểm tra',
                'note' => 'Ghi chú'
            ];
            $sheet->getStyle([1, 7, 4 + (count($lot_id_array) > 1 ? (count($lot_id_array) - 1) : 0), $row_index + count($next_rows) - 1])->applyFromArray(array_merge(ExcelStyleHelper::borders(), ExcelStyleHelper::alignment('left')));
            foreach ($next_rows as $key => $value) {
                $sheet->setCellValue('A' . $row_index, $value)->mergeCells('A' . $row_index . ':C' . $row_index)->getStyle('A' . $row_index . ':C' . $row_index)->applyFromArray(array_merge(ExcelStyleHelper::bold(), ExcelStyleHelper::alignment('center')));
                $row_index++;
                $data[$key] = [];
                foreach ($infos as $info) {
                    if ($info['lot_id'] && isset($checked_data[$info['lot_id']])) {
                        $data[$key][$info['lot_id']] = $info[$key] ?? null;
                    }
                }
            }
            // return $data;
            $sheet->setCellValue('D7', 'Mã pallet/thùng')->mergeCells([4, 7, 4 + (count($lot_id_array) > 1 ? (count($lot_id_array) - 1) : 0), 7])->getStyle([4, 7, 4 + (count($lot_id_array) > 1 ? (count($lot_id_array) - 1) : 0), 7])->applyFromArray(ExcelStyleHelper::alignment('center'));
            $sheet->fromArray($lot_id_array, null, 'D8');
            $sheet->fromArray($data, null, 'D9', true);
            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
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
                $line = Line::find($line_id);
                if (!$line) continue;
                $sum_ok = 0;
                $sum_ng = 0;
                foreach ($qcHistories as $qcHistory) {
                    $final_result = $qcHistory->testCriteriaHistories->pluck('result')->toArray();
                    if (count($final_result) >= 3) {
                        if (in_array('NG', $final_result)) {
                            $sum_ng += 1;
                        } else {
                            $sum_ok += 1;
                        }
                    }
                }

                $data[$line_id]['cong_doan'] = $line->name;
                $data[$line_id]['sum_lot_kt'] = count($qcHistories);
                $data[$line_id]['sum_lot_ok'] = $sum_ok;
                $data[$line_id]['sum_lot_ng'] = $sum_ng;
                $data[$line_id]['sum_ty_le_ng'] = count($qcHistories) ? number_format($sum_ng / count($qcHistories) * 100, 2) : 0;
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

    //Lỗi
    public function qcErrorList(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = $this->pqcHistoryQuery($request);
        $totalPage = $query->count();
        $records = $query->offset($page * $pageSize)->limit($pageSize)->get();
        $result = $this->parseErrorData($records);
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
        return $this->success(['data' => $result, "totalPage" => $totalPage, 'columns' => $columns]);
    }

    public function parseErrorData($qc_histories, $columns = [])
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
            $item = [
                'stt' => $index + 1,
                'ngay_sx' => Carbon::parse($qc_history->created_at)->format('d/m/Y H:i:s'),
                'ca_sx' => $ca_sx,
                'xuong' => $qc_history->line->factory->name ?? "Giấy",
                'cong_doan' => $qc_history->infoCongDoan->line->name ?? '',
                'ten_san_pham' => $qc_history->infoCongDoan->product->name ?? "",
                'lo_sx' => $qc_history->infoCongDoan->lo_sx,
                'lot_id' => $qc_history->infoCongDoan->lot_id,
                'sl_dau_vao_hang_loat' => $qc_history->infoCongDoan->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra_ok' => ($qc_history->infoCongDoan->sl_dau_ra_hang_loat ?? 0) - ($qc_history->infoCongDoan->sl_tem_vang ?? 0) - ($qc_history->infoCongDoan->sl_ng ?? 0),
                'sl_ng' => $qc_history->infoCongDoan->sl_ng ?? 0,
                'sl_tem_vang' => $qc_history->infoCongDoan->sl_tem_vang ?? 0,
            ];

            if (!empty($columns)) {
                $errors = [];
                foreach (($qc_history->errorHistories ?? []) as $error_id => $value) {
                    $errors['ng' . $value->error_id] = $value->quantity;
                }
                $errorColumns = collect($columns)->flatten(1)->all();
                foreach ($errorColumns as $error_id => $value) {
                    $item[$value['key']] = $errors[$value['key']] ?? '';
                }
            } else {
                foreach (($qc_history->errorHistories ?? []) as $error_id => $value) {
                    $item['ng' . $value->error_id] = $value->quantity;
                }
            }
            $index++;
            $record[] = $item;
        }
        return $record;
    }

    public function exportQCErrorList(Request $request)
    {
        $query = $this->pqcHistoryQuery($request);
        $records = $query->get();
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
        $result = $this->parseErrorData($records, $columns);
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
            Log::debug($itemsByDate);
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

    //=========================AUTO PLAN================================//
    public function getProductionSteps($productId)
    {
        // Bước 1: Truy vấn để lấy các công đoạn từ bảng spec theo product_id và slug 'hanh-trinh-san-xuat'
        // Sắp xếp theo thứ tự giảm dần (DESC) để tính toán sản lượng
        return Spec::where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            ->where('line_id','<>',29)
            ->whereRaw('value REGEXP "^[0-9]+$"')
            ->orderBy('value', 'desc')
            ->get();
    }

    function getOrderedProductionSteps($productId)
    {
        // Bước 8: Truy vấn để lấy các công đoạn từ bảng spec theo product_id và sắp xếp theo value ASC
        return Spec::where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            ->whereRaw('value REGEXP "^[0-9]+$"')
            ->orderBy('value', 'asc')
            ->get();
    }

    function calculateProductionWastage($productId)
    {

        // Lấy hao phí vào hàng từ bảng spec
        $inputWaste = Spec::where('product_id', $productId)
            ->where('slug', 'hao-phi-vao-hang-cac-cong-doan')
            ->pluck('value', 'line_id')
            ->toArray();
        return (array)$inputWaste;
    }

    function calculateProductionOutput($productId, $lineId, $quantity)
    {
        $productionWaste = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'hao-phi-san-xuat-cac-cong-doan')
            ->first();

        $inputWaste = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'hao-phi-vao-hang-cac-cong-doan')
            ->first();

        $line_inventory = LineInventories::where('line_id', $lineId)->where('product_id', $productId)->first();
        $remain = $quantity - ($line_inventory->quantity ?? 0);
        if ($remain > 0) {
            $quantity = $remain;
        } else {
            return 0;
        }
        if ($productionWaste) {
            $quantity += $quantity * ($productionWaste->value / 100);
        }

        if ($inputWaste) {
            $quantity += $inputWaste->value;
        }

        return $quantity;
    }

    function modifiedCalculateProductionOutput($lineId, $quantity, $lineProductionWaste = [], $lineInputWaste = [], $lineInventory = [])
    {
        $productionWaste = $lineProductionWaste[$lineId] ?? 0;

        $inputWaste = $lineInputWaste[$lineId] ?? 0;

        $line_inventory = $lineInventory[$lineId] ?? 0;
        $remain = $quantity - $line_inventory;
        if ($remain > 0) {
            $quantity = $remain;
        } else {
            return 0;
        }
        if ($productionWaste) {
            $quantity += Ceil($quantity * ($productionWaste / 100));
        }

        if ($inputWaste) {
            $quantity += $inputWaste;
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
    function getLotSizes($productId, array $lineIds)
    {
        $specs = Spec::whereIn('line_id', $lineIds)
            ->where('product_id', $productId)
            ->where('slug', 'so-luong')
            ->get();

        $lotSizes = $specs->mapWithKeyss(function ($spec) {
            return [$spec->line_id => $spec->value];
        })->toArray();
        $result = [];
        foreach ($lineIds as $id) {
            $result[$id] = $lotSizes[$id] ?? 11000;
        }

        return $result;
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

    function getEfficiencys($productId, array $lineIds)
    {
        $specs = Spec::whereIn('line_id', $lineIds)
            ->where('product_id', $productId)
            ->where('slug', 'nang-suat-an-dinhgio')
            ->get();

        $efficiencies = $specs->mapWithKeyss(function ($spec) {
            return [$spec->line_id => $spec->value];
        })->toArray();

        $result = [];
        foreach ($lineIds as $id) {
            $result[$id] = $efficiencies[$id] ?? 0;
        }

        return $result;
    }

    function getBottleneckStage($productId)
    {
        // Truy vấn để lấy công đoạn bottleneck từ bảng spec theo slug 'bottleneck'
        $bottleneckSpec = Spec::where('product_id', $productId)
            ->where('slug', 'nang-suat-an-dinhgio')
            ->orderByRaw('CAST(value AS UNSIGNED) ASC')
            ->first();

        return $bottleneckSpec;
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
        // Lấy thứ tự ưu tiên của máy
        $machinePriorityOrder = MachinePriorityOrder::where('product_id', $productId)
            ->where('line_id', $lineId)
            ->orderBy('priority')
            ->pluck('priority', 'machine_id')
            ->toArray();
        if(count($machinePriorityOrder) == 0){
            $machineList = Machine::where('line_id', $lineId)->get()->toArray();
            foreach ($machineList as $key => $value) {
                $machinePriorityOrder[$value->code]['priority'] = 1;
            }
        }
        // Lấy danh sách mã máy từ thứ tự ưu tiên
        $machineCodes = array_keys($machinePriorityOrder);

        // Truy vấn danh sách máy dựa trên line_id và mã máy
        if ($lineId == 29) {
            $machines = Machine::select('code', 'available_at', 'line_id')
                ->where('line_id', $lineId)
                ->get();
        } else {
            $machines = Machine::select('code', 'available_at', 'line_id')
                ->where('line_id', $lineId)
                ->whereIn('code', $machineCodes)
                ->get();
        }

        // Cập nhật thời gian sẵn sàng và ưu tiên cho từng máy
        foreach ($machines as $machine) {
            if (isset($machine_available_list[$machine->code])) {
                $machine->available_at = $machine_available_list[$machine->code];
            }
            $machine->priority = $machinePriorityOrder[$machine->code] ?? PHP_INT_MAX;
        }

        // Phân chia máy thành hai nhóm: trước và sau thời gian bắt đầu
        [$beforeStart, $afterStart] = $machines->partition(function ($machine) use ($startTime) {
            $readyTime = Carbon::parse($machine->available_at);
            return $readyTime->lessThanOrEqualTo($startTime) || is_null($machine->available_at);
        });
        // Sắp xếp nhóm trước thời gian bắt đầu theo ưu tiên
        if ($beforeStart->isNotEmpty()) {
            $beforeStart = $beforeStart->sortBy('priority');
        }

        // Sắp xếp nhóm sau thời gian bắt đầu theo thời gian sẵn sàng và ưu tiên
        if ($afterStart->isNotEmpty()) {
            $afterStart = $afterStart->sort(function ($a, $b) {
                $readyTimeA = Carbon::parse($a->available_at);
                $readyTimeB = Carbon::parse($b->available_at);

                if ($readyTimeA->equalTo($readyTimeB)) {
                    return $a->priority - $b->priority;
                }
                return $readyTimeA->lessThan($readyTimeB) ? -1 : 1;
            });
        }
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

    public function adjustTimeWithinShift($startTime, $duration, $machineId, $shiftPreparationTime)
    {
        if (!$startTime instanceof Carbon) {
            $startTime = Carbon::parse($startTime, 'Asia/Bangkok');
        } else {
            $startTime->setTimezone('Asia/Bangkok');
        }

        $currentTime = $startTime->copy();
        $remainingDuration = $duration;
        $maxDays = 30;
        $daysProcessed = 0;

        while ($remainingDuration > 0 && $daysProcessed < $maxDays) {
            $currentDate = $currentTime->copy()->startOfDay();
            $dateString = $currentDate->toDateString();
            $productionShifts = $this->getMachineProductionShifts($machineId, $dateString);

            if ($productionShifts->isEmpty()) {
                $currentTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }

            foreach ($productionShifts as $shift) {
                $shiftStart = Carbon::parse("{$dateString} {$shift->start_time}", 'Asia/Bangkok');
                $shiftEnd = Carbon::parse("{$dateString} {$shift->end_time}", 'Asia/Bangkok');

                if ($shiftStart->hour < 7 && $startTime->hour >= 7) {
                    $shiftStart->addDay();
                }

                if ($shiftStart->hour >= 7 && $startTime->hour < 7) {
                    $shiftStart->subDay();
                }

                if ($shiftEnd->hour < 7 && $startTime->hour >= 7) {
                    $shiftEnd->addDay();
                }
                if ($currentTime->lessThan($shiftStart) && $currentTime->day == $shiftStart->day) {
                    if (!$currentTime->equalTo($startTime)) {
                        $currentTime->addMinutes($shiftPreparationTime);
                    }
                    $currentTime = $shiftStart->copy();
                }

                if ($currentTime->between($shiftStart, $shiftEnd) || $currentTime->equalTo($shiftStart)) {
                    $availableTime = $shiftEnd->diffInMinutes($currentTime);

                    if ($availableTime >= $remainingDuration) {
                        $endTime = $currentTime->copy()->addMinutes($remainingDuration);
                        return [$startTime, $endTime];
                    } else {
                        $currentTime = $shiftEnd->copy();
                        $remainingDuration -= $availableTime;
                    }
                } elseif ($currentTime->greaterThanOrEqualTo($shiftEnd)) {
                    continue;
                }
            }

            $currentTime->addDay()->startOfDay();
            $daysProcessed++;
        }
        return [$startTime, $currentTime];
    }

    function getShiftPreparationTime($productId, $lineId)
    {
        $preparationTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'chuan-bidau-ca')
            ->first();

        return $preparationTimeSpec ? $preparationTimeSpec->value : 0;
    }

    function getNumberMachine($orderId)
    {
        $numberMachineOrders = NumberMachineOrder::where('product_order_id', $orderId)->get();
        return $numberMachineOrders->pluck('number_machine', 'line_id');
    }

    private function getAvailableMaterialsForProduct($product)
    {
        $materials = $product->materials;
        foreach ($materials as $key => $material) {
            if ($key == 0) {
                return $material->id;
            }
        }
        throw new Exception("Không có nguyên vật liệu khả dụng cho sản phẩm {$product->name}.");
    }

    private function groupOrdersByAvailableMaterial($orders)
    {
        $groupedOrders = [];

        foreach ($orders as $order) {
            try {
                $materialId = $this->getAvailableMaterialsForProduct($order->product);
                if (!isset($groupedOrders[$materialId])) {
                    $groupedOrders[$materialId] = [];
                }

                $groupedOrders[$materialId][] = $order;
            } catch (Exception $e) {
                continue;
            }
        }

        return $groupedOrders;
    }

    public function generateProductionPlan(Request $request)
    {
        $orderIds = $request->order_id;
        $data = [];
        $machine_available_list = [];
        $orders = ProductOrder::with(['product.materials', 'customer'])
            ->whereIn('id', $orderIds)
            ->get();
        $groupedOrders = $this->groupOrdersByAvailableMaterial($orders);
        $sortedGroups = [];
        foreach ($groupedOrders as $materialId => $groupOrders) {
            $sortedOrders = collect($groupOrders)->sort(function ($a, $b) {
                if ($a->delivery_date != $b->delivery_date) {
                    return $a->delivery_date < $b->delivery_date ? -1 : 1;
                }
                return 0;
            });

            $sortedGroups[$materialId] = $sortedOrders;
        }
        $sortedMaterialGroups = collect($sortedGroups)->sort(function ($a, $b) {
            $earliestDeadlineA = $a->min('delivery_date');
            $earliestDeadlineB = $b->min('delivery_date');
            return $earliestDeadlineA <=> $earliestDeadlineB;
        });

        $prioritizedOrders = $sortedMaterialGroups->flatten(1);
        $sortedByProductId = collect($prioritizedOrders)->groupBy('product_id')->flatten(1);
        foreach ($sortedByProductId as $index => $order) {
            try {
                $result = $this->processProductionPlan($order, $index, $machine_available_list);
                if ($result) {
                    $data[] = $result;
                }
            } catch (\Throwable $th) {
                throw $th;
                return $this->failure('', $th->getMessage());
            }
        }
        if (count($data) <= 0) {
            return $this->failure('', 'Không có kế hoạch nào được tạo');
        }
        return $this->success($data);
    }

    function calculateEndTime1($startTime, $taskTime, $lotSize, $rollChangeTime, $numLots, $setupTime)
    {
        $workdayStartHour = 7 * 60 + 30; // 7:30 sáng (phút từ 0h)
        $workdayEndHour = 19 * 60;       // 19:00 tối (phút từ 0h)
        $workdayDuration = $workdayEndHour - $workdayStartHour; // Thời gian làm việc mỗi ngày (phút)

        // Tổng thời gian công việc
        $totalMinutes = ((($taskTime * $lotSize) + $rollChangeTime) * $numLots) + $setupTime;

        // Chuyển thời gian bắt đầu sang phút từ 0h
        $currentMinutes = $startTime->hour * 60 + $startTime->minute;

        // Nếu thời gian bắt đầu trước giờ làm việc, điều chỉnh đến 7:30
        if ($currentMinutes < $workdayStartHour) {
            $currentMinutes = $workdayStartHour;
        }

        // Tính toán
        while ($totalMinutes > 0) {
            // Tính số phút còn lại trong ngày làm việc hiện tại
            $remainingMinutesToday = $workdayEndHour - $currentMinutes;

            if ($totalMinutes <= $remainingMinutesToday) {
                // Nếu thời gian đủ để hoàn thành công việc trong ngày
                $currentMinutes += $totalMinutes;
                $totalMinutes = 0;
            } else {
                // Nếu không, tiêu thụ hết thời gian trong ngày và chuyển sang ngày kế tiếp
                $totalMinutes -= $remainingMinutesToday;
                $currentMinutes = $workdayStartHour; // Đặt lại thời gian bắt đầu ngày tiếp theo
            }
        }

        // Chuyển phút từ 0h thành giờ và phút thực tế
        $daysAdded = intdiv($currentMinutes, 24 * 60); // Số ngày vượt quá
        $finalMinutes = $currentMinutes % (24 * 60);  // Phút còn lại trong ngày
        $hour = intdiv($finalMinutes, 60);
        $minute = $finalMinutes % 60;

        // Trả về đối tượng Carbon với ngày giờ được tính toán
        return $startTime->copy()->addDays($daysAdded)->setTime($hour, $minute);
    }

    function getSpecByKey($productionSteps, $productId, $materialId, $key)
    {
        $lineIdFirst = end($productionSteps);
        // Khởi tạo mảng kết quả
        $specData = [];
        // Nếu công đoạn đầu tiên là gấp dán, lấy từ $material
        if ($lineIdFirst == 24) {
            $specData = Spec::where('product_id', $materialId)
                ->where('line_id', 24)
                ->where('slug', $key)
                ->pluck('value', 'line_id')
                ->all();
            // Loại bỏ line_id = 24 khỏi danh sách $productionSteps
            $productionSteps = array_diff($productionSteps, [24]);
        }
        // Lấy dữ liệu còn lại từ $product
        $specForOthers = Spec::where('product_id', $productId)
            ->whereIn('line_id', $productionSteps)
            ->where('slug', $key)
            ->pluck('value', 'line_id')
            ->all();
        // Gộp kết quả từ hai truy vấn
        $specData = ($specData + $specForOthers);
        return $specData;
    }

    public function processProductionPlan($order, $orderIndex = 0, &$machine_available_list = [])
    {
        // 1. Kiểm tra điều kiện đầu vào
        if (!$order->sl_giao_sx) {
            throw new Exception("Không có số lượng giao sản xuất", 1);
        }

        // 2. Khởi tạo các biến cơ bản
        $orderId      = $order->id;
        $productId    = $order->product_id;
        $inventory    = Inventory::where('product_id', $productId)->first();

        // Dùng max(0, ...) để tránh trường hợp âm.
        $initialQuantity = max(0, ($order->sl_giao_sx - ($inventory->sl_ton ?? 0)));

        // 3. Lấy danh sách công đoạn & bom
        $productionSteps       = $this->getProductionSteps($productId);
        $lineProductionArray   = $productionSteps->pluck('line_id')->toArray();
        $materialId            = $productId;
        // Nếu công đoạn cuối là line_id = 24 và tìm thấy bom
        if (end($lineProductionArray) == 24) {
            $bom = Bom::where('product_id', $productId)
                ->whereRaw('priority REGEXP "^[0-9]+$"')
                ->first();
            if ($bom) {
                $materialId = $bom->material_id;
            }
        }

        // 4. Lấy các thông số cần thiết
        $stepQuantities       = [];
        $productionTimes      = [];
        $numberMachineByStep  = $this->getNumberMachine($orderId);
        $inputWaste           = $this->calculateProductionWastage($productId);
        $lineProductionWaste  = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'hao-phi-san-xuat-cac-cong-doan');
        $lineInputWaste       = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'hao-phi-vao-hang-cac-cong-doan');
        $lineInventory        = LineInventories::where('product_id', $productId)->pluck('quantity', 'line_id');
        $efficiencies         = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'nang-suat-an-dinhgio');

        // 5. Tính toán số lượng và thời gian sản xuất cho từng công đoạn
        $line_must_run = [];
        foreach ($productionSteps as $step) {
            $lineId            = $step->line_id;
            $lineName          = $step->line->name;
            $calculatedQuantity = $this->modifiedCalculateProductionOutput(
                $lineId,
                $initialQuantity,
                $lineProductionWaste,
                $lineInputWaste,
                $lineInventory
            );

            // Lưu lại công đoạn nếu có số lượng > 0
            if ($calculatedQuantity !== 0) {
                $line_must_run[] = $lineId;
            }

            // Lưu kết quả tính được
            $stepQuantities[$lineId] = $calculatedQuantity;

            // Tính thời gian (giờ) nếu có năng suất
            $efficiencySpec = $efficiencies[$lineId] ?? throw new Exception("Không tìm thấy năng suất cho sản phẩm $productId tại công đoạn $lineName", 1);
            if ($efficiencySpec > 0) {
                // round(..., 2) làm tròn 2 chữ số thập phân
                $productionTimes[$lineId] = round($calculatedQuantity / $efficiencySpec, 2);
            }

            // Số lượng đầu ra của công đoạn này = đầu vào cho công đoạn kế
            $initialQuantity = $calculatedQuantity;
        }

        // 6. Lấy thứ tự công đoạn sắp xếp (ordered)
        $orderedSteps = $this->getOrderedProductionSteps($productId)
            ->filter(fn($value) => in_array($value->line_id, $line_must_run))
            ->values();
        // Nếu không có công đoạn nào phải chạy => không cần tính tiếp
        if ($orderedSteps->count() <= 0) {
            return null;
        }
        foreach($orderedSteps as $key=> $step){
            if($key == 0 && $step->line_id == 24){
                $step->product_id = $materialId;
            }else{
                $step->product_id = $productId;
            }
        }
        // 7. Chuẩn bị các biến để tính lô/lots
        $stepEndTimes      = [];
        $lots              = [];
        $plans             = [];
        $lot_plans         = [];
        $machine_input     = [];
        $isExceedDeliveryTime = false;
        $machine_in_line   = [];

        // Tạo mã lô sản xuất (LOSX)
        $losx_id  = Losx::generateUniqueIdPreview($orderIndex);
        $lo_sx    = Losx::where('product_order_id', $orderId)->first();
        if ($lo_sx) {
            $losx_id = $lo_sx->id;
        }

        // Lưu lại input để cập nhật/khởi tạo Losx
        $losx_input = [
            'product_order_id' => $orderId,
            'id'               => $losx_id,
        ];

        // 8. Lấy các thông số về lô
        $lineLotSize          = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'so-luong');
        $rollChangeTimes      = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'thoi-gian-len-xuong-cuon');
        $rollsPerTransports   = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'so-luong-cuon-1-lan-van-chuyen-cuon');
        $lineSetupTime        = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'vao-hang-setup-may');
        $preparationTimeSpecs = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'chuan-bidau-ca');
        $transportTimeSpecs   = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau');

        // 9. Vòng lặp các công đoạn đã sắp xếp
        $startTime = null;
        foreach ($orderedSteps as $index => $step) {
            $productId = $step->product_id;
            $lineId   = $step->line_id;
            $quantity = $stepQuantities[$lineId];

            // Mặc định 1 lot = 11000 (nếu lấy từ spec thì dùng spec, nếu không có thì gán mặc định)
            $lotSize  = $lineLotSize[$lineId] ?? 11000;

            // Tạo mảng lots cho lineId này
            if (!isset($lots[$lineId])) {
                $lots[$lineId] = [];
            }

            $product = Product::find($productId);
            if (!$product) {
                throw new Exception("Không tìm thấy mã sản phẩm " . $productId, 1);
            }

            $rollChangeTime = $rollChangeTimes[$lineId] ?? 0;
            $efficiency     = $efficiencies[$lineId] ?? 0;
            // taskTime = số phút để làm xong 1 sản phẩm (minute/product)
            $taskTime       = $efficiency > 0 ? 60 / $efficiency : 0;

            // Số lượng cuộn 1 lần vận chuyển
            $rollsPerTransport = $rollsPerTransports[$lineId] ?? 0;
            $setupTime         = $lineSetupTime[$lineId] ?? 0;
            $shiftPrepTime     = $preparationTimeSpecs[$lineId] ?? 0;
            $transportTime     = $transportTimeSpecs[$lineId] ?? 0;

            // 9.1. Tính $startTime cho công đoạn đầu tiên hay tiếp theo
            if (!isset($startTime)) {
                // Công đoạn đầu tiên => bắt đầu từ 7h30 sáng ngày hôm sau
                $startTime = Carbon::now()->addDay()->setTime(7, 30, 0);
            } else {
                // Điều chỉnh rollsPerTransport nếu cần
                $rollsPerTransport = $this->adjustRollsPerTransport(
                    $rollsPerTransport,
                    $quantity,
                    $lotSize,
                    $index,
                    $orderedSteps,
                    $inputWaste
                );

                // Lấy endTime của công đoạn trước => + transportTime
                //   (Cần cẩn thận kiểm tra index - 1, máy, v.v...)
                try {
                    $prevLineId = $orderedSteps[$index - 1]->line_id ?? null;
                    if (
                        $prevLineId
                        && isset($machine_in_line[$prevLineId])
                        && $machine_in_line[$prevLineId] > 0
                    ) {
                        $prevMachineCount = $machine_in_line[$prevLineId];
                        $transportIndex   = ($rollsPerTransport / $prevMachineCount) - 1;

                        // Kiểm tra phần tử lots cũ
                        if (
                            isset($lots[$prevLineId][$prevMachineCount - 1]) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1][$transportIndex]) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1][$transportIndex]['endTime'])
                        ) {
                            $prevEndTime = $lots[$prevLineId][$prevMachineCount - 1][$transportIndex]['endTime'];
                            $startTime   = $prevEndTime->copy()->addMinutes($transportTime);
                        }
                    }
                } catch (\Throwable $th) {
                    Log::debug([$rollsPerTransport / $machine_in_line[$orderedSteps[$index - 1]->line_id]]);
                    throw $th;
                }
            }

            // 9.2. Lấy máy có sẵn
            $numMachines = $numberMachineByStep[$lineId] ?? 0;
            $machines    = $this->getMachineReady($lineId, $numMachines, $productId, $machine_available_list, $startTime);

            $numMachines  = count($machines);
            $machine_in_line[$lineId] = $numMachines;

            // Mỗi máy xử lý 1 phần quantity
            $quantityPerMachine = $numMachines > 0 ? ceil($quantity / $numMachines) : $quantity;
            $numLots            = ceil($quantityPerMachine / $lotSize);
            $lotIndexOffset     = 0;

            // 9.3. Vòng lặp từng máy
          
            foreach ($machines as $machineIndex => $machine) {
                $machineReadyTime = Carbon::parse($machine->available_at, 'Asia/Bangkok');
                if (!$startTime->greaterThan($machineReadyTime)) {
                    $startTime = $machineReadyTime;
                }
                // Ước tính endTime cho toàn bộ lot
                $endTime = $this->calculateEndTime1($startTime, $taskTime, $lotSize, $rollChangeTime, $numLots, $setupTime);
                $stepEndTimes[$lineId] = $endTime;

                $planInput = [
                    'product_order_id'  => $orderId,
                    'ngay_dat_hang'     => $order->order_date,
                    'ngay_sx'           => $startTime,
                    'ngay_giao_hang'    => $order->delivery_date,
                    'line_id'           => $lineId,
                    'cong_doan_sx'      => $step->line->name,
                    'ca_sx'             => 1,
                    'delivery_date'     => $order->delivery_date ? date('Y-m-d', strtotime($order->delivery_date)) : null,
                    'machine_id'        => $machine->code,
                    'product_id'        => $productId,
                    'ten_san_pham'      => $product->name ?? "",
                    'khach_hang'        => $order->customer->name ?? "",
                    'lo_sx'             => $losx_id,
                    'thu_tu_uu_tien'    => 1,
                    'nhan_luc'          => 1,
                    'tong_tg_thuc_hien' => ($quantity * $taskTime)
                        + ($rollChangeTime * $numLots)
                        + $setupTime,
                    'thoi_gian_bat_dau' => $startTime,
                    'thoi_gian_ket_thuc' => $endTime,
                    'sl_giao_sx'        => $quantityPerMachine,
                ];

                // 9.4. Xây dựng danh sách các lots chi tiết
                $lot_in_plan = [];
                // Đếm số lot đã có trong InfoCongDoan
                $countLot = InfoCongDoan::query()->where([
                    'lo_sx'   => $losx_id,
                    'line_id' => $lineId
                ])->count() + $lotIndexOffset;

                for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
                    $countLot++;
                    // Tạo lot_id (vd: LOSX123.L.0001)
                    $lotId = $losx_id . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT);

                    // Tính thời gian start - end cho lot
                    $lotStartTime = ($lotIndex == 1)
                        ? $startTime
                        : $lots[$lineId][$machineIndex][$lotIndex - 2]['endTime'];

                    // Ở đây logic cũ có chỗ chia “quantity % lotSize” cho lot đầu tiên:
                    // - T tuỳ chỉnh lại thành quantityPerLot = $lotSize, trừ trường hợp lot đầu tiên
                    //   trên máy đầu tiên, ta có thể “tận dụng” remainder. 
                    $quantityPerLot = (
                        $lotIndex == 1 && $machineIndex == 0
                    )
                        ? ($quantity % $lotSize ?: $lotSize)
                        : $lotSize;

                    // Điều chỉnh thời gian theo ca
                    list($lotStartTime, $lotEndTime) = $this->adjustTimeWithinShift(
                        $lotStartTime,
                        ($taskTime * $quantityPerLot) + $rollChangeTime,
                        $machine->code,
                        $shiftPrepTime
                    );

                    // Kiểm tra vượt deadline
                    if ($order->delivery_date && $lotStartTime->greaterThan(Carbon::parse($order->delivery_date))) {
                        $isExceedDeliveryTime = true;
                    }

                    // Tạo dữ liệu lot
                    $lotPlanInput = [
                        'lot_id'           => $lotId,
                        'lo_sx'            => $losx_id,
                        'line_id'          => $lineId,
                        'product_id'       => $productId,
                        'machine_code'     => $machine->code,
                        'start_time'       => $lotStartTime,
                        'end_time'         => $lotEndTime,
                        'quantity'         => $quantityPerLot,
                        'lot_size'         => $quantityPerLot,
                        'product_order_id' => $orderId,
                        'customer_id'      => $order->customer_id,
                        'sl_giao_sx'       => $quantityPerLot,
                        'ca_sx'            => 1,
                        'cong_doan_sx'     => $step->line->name,
                        'machine_id'       => $machine->code,
                        'ten_san_pham'     => $product->name ?? "",
                        'khach_hang'       => $order->customer_id,
                        'thoi_gian_bat_dau' => $lotStartTime,
                        'thoi_gian_ket_thuc' => $lotEndTime,
                        'is_exceed_time'   => $isExceedDeliveryTime,
                    ];

                    // Thêm lot vào danh sách
                    $lot_plans[]  = $lotPlanInput;
                    $lot_in_plan[] = $lotPlanInput;

                    // Lưu vào lots chung
                    $lots[$lineId][$machineIndex][] = [
                        'lot_id'    => $lotId,
                        'quantity'  => $quantityPerLot,
                        'startTime' => $lotStartTime,
                        'endTime'   => $lotEndTime,
                    ];
                }

                // Cập nhật plan
                $planInput['lots']            = $lot_in_plan;
                $planInput['is_exceed_time']  = $isExceedDeliveryTime;

                // Cập nhật thời gian kết thúc cho plan
                if (!empty($lots[$lineId][$machineIndex])) {
                    $stepEndTimes[$lineId]          = end($lots[$lineId][$machineIndex])['endTime'];
                    $planInput['thoi_gian_ket_thuc'] = $stepEndTimes[$lineId];
                }

                // Lưu plan
                $plans[] = $planInput;
                $lotIndexOffset += $numLots;

                // Cập nhật thời gian available của máy
                $machine_input[] = [
                    'machine_code' => $machine->code,
                    'available_at' => $stepEndTimes[$lineId],
                ];
                if (!isset($machine_available_list[$machine->code]) ||
                    $stepEndTimes[$lineId]->greaterThan($machine_available_list[$machine->code])
                ) {
                    $machine_available_list[$machine->code] = $stepEndTimes[$lineId]->format('Y-m-d H:i:s');
                }
            }
        }

        // 10. Tổng hợp dữ liệu lô sản xuất (LOSX)
        $losx_input['lo_sx']           = $losx_id;
        $losx_input['sl_giao_sx']      = $order->sl_giao_sx;
        $losx_input['product_id']      = $productId;
        $losx_input['product_name']    = $product->name ?? "";
        $losx_input['thoi_gian_bat_dau'] = !empty($plans) ? (reset($plans)['thoi_gian_bat_dau'] ?? null) : null;
        $losx_input['thoi_gian_ket_thuc'] = !empty($plans) ? (end($plans)['thoi_gian_ket_thuc'] ?? null) : null;
        $losx_input['khach_hang']      = $order->customer->name ?? "";
        $losx_input['delivery_date']   = $order->delivery_date ? date('d/m/Y', strtotime($order->delivery_date)) : null;
        $losx_input['plans']           = $plans;
        $losx_input['is_exceed_time']  = $isExceedDeliveryTime;

        // 11. Trả về dữ liệu
        return [
            'lots'     => $lot_plans,
            'plans'    => $plans,
            'machines' => $machine_input,
            'lo_sx'    => $losx_input,
        ];
    }

    /**
     * Ví dụ hàm tinh chỉnh số lượng cuộn vận chuyển giữa 2 công đoạn:
     */
    private function adjustRollsPerTransport(
        $rollsPerTransport,
        $quantity,
        $lotSize,
        $index,
        $orderedSteps,
        $inputWaste
    ) {
        // Nếu 0 hoặc nếu ceil($quantity / $lotSize) < $rollsPerTransport => điều chỉnh
        if ($rollsPerTransport === 0 || ceil($quantity / $lotSize) < $rollsPerTransport) {
            // line hiện tại
            $currentLineId = $orderedSteps[$index]->line_id;
            // Lấy hao phí đầu vào (nếu có)
            $waste = $inputWaste[$currentLineId] ?? 0;
            // Tính lại
            $rollsPerTransport = ceil($quantity / ($lotSize + $waste));
        }
        return $rollsPerTransport;
    }
    public function processProductionPlanV1($order, $orderIndex = 0, &$machine_available_list = [])
    {
        if (!$order->sl_giao_sx) {
            throw new Exception("Không có số lượng giao sản xuất", 1);
        }
        $stepQuantities = [];
        $productionTimes = [];
        $line_must_run = [];
        $productId = $order->product_id;
        $workingHoursPerDay = 8.0;
        $finishTime = 0;

        // Tính số lượng cần sản xuất trừ tồn
        $inventory = Inventory::where('product_id', $productId)->first();
        $quantity = $inventory ? $order->sl_giao_sx - $inventory->sl_ton : $order->sl_giao_sx;

        $productionSteps = $this->getProductionSteps($productId);
        $bottleneckSpec = $this->getBottleneckStage($productId);
        $taskTime = 1 / $bottleneckSpec->value;
        $lineIDs = $productionSteps->pluck('line_id')->toArray();
        $lotSizes = $this->getLotSizes($productId, $lineIDs);
        $efficiencySpecs = $this->getEfficiencys($productId, $lineIDs);
        return $lotSizes;
        foreach ($productionSteps as $step) {
            $calculatedQuantity = $this->calculateProductionOutput($productId, $step->line_id, $quantity);
            $lotsize = $lotSizes[$step->line_id];
            if ($calculatedQuantity !== 0) {
                $line_must_run[] = $step->line_id;
            };
            $stepQuantities[$step->line_id] = $calculatedQuantity;
            $efficiencySpec = $this->getEfficiency($productId, $step->line_id);
            if ($efficiencySpec > 0) {
                $productionTimes[$step->line_id] = round($calculatedQuantity / $efficiencySpec, 2);
            }
            $quantity = $calculatedQuantity;
            if ($step->line_id != $bottleneckSpec->line_id) {
                $finishTime += round($lotsize / $efficiencySpec, 2);
            } else {
                $bottleneckTime = round($calculatedQuantity / $efficiencySpec, 2);
            }
        }

        $orderedSteps = $productionSteps->reverse()->values();
        $orderedSteps = $orderedSteps->filter(function ($value) use ($line_must_run) {
            return in_array($value->line_id, $line_must_run);
        })->values();
        $totalProductionTime = $finishTime + $bottleneckTime;
        $startDate = Carbon::now()->addDay()->setTime(7, 30, 0);
        $endDate = Carbon::parse($order->delivery_date)->setTime(12, 00, 00);
        $requiredWorkingDays = ceil($totalProductionTime / $workingHoursPerDay);
        $estimatedEndDate = $startDate->copy()->addDays($requiredWorkingDays - 1);
        if ($estimatedEndDate->gt($endDate)) {
            $canMeetDeadline = false;
        } else {
            $canMeetDeadline = true;
        }
        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'estimated_end_date' => $estimatedEndDate,
        ];
        return [
            'lots' => $lot_plans,
            'plans' => $plans,
            'machines' => $machine_input,
            'lo_sx' => $losx_input,
        ];
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
                foreach ($plan['lots'] ?? [] as $lot_plan) {
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

    public function printProductionPlan(Request $request)
    {
        $plans = $request->plans ?? [];
        if (count($plans) <= 0) {
            return $this->failure('', 'Không có dữ liệu kế hoạch lô');
        }
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Đặt tên tiêu đề bảng
        $header = [
            '',
            'Thứ tự ưu tiên',
            'Thời gian bắt đầu(h)',
            'Thời gian kết thúc(h)',
            'Ngày SX',
            'Ca SX',
            'Công đoạn SX',
            'Máy sản xuất',
            'Mã sản phẩm',
            'Khách hàng',
            'Tên Sản Phẩm',
            'Mã đơn hàng',
            'Ngày giao hàng',
            'SL Tổng ĐH (đvt: túi/mảnh)',
            'SL NVL đầu vào (ĐVT: Tờ)',
            'SL thành phẩm (ĐVT: Tờ)',
            'SL giao SX (đvt: túi/mảnh)',
            'KQSX (đvt: túi/mảnh)',
            'SL còn lại (đvt: túi/mảnh)',
            'Khổ Giấy (mm)',
            'Tốc độ',
            'UPH',
        ];

        // Thiết lập các cột tiêu đề
        foreach ($header as $col => $title) {
            $sheet->setCellValue([$col + 1, 3], $title);
        }

        // Duyệt dữ liệu $plans và ghi vào file Excel
        $rowIndex = 4;
        foreach ($plans as $index => $plan) {
            $sheet->setCellValue("B$rowIndex", $plan['thu_tu_uu_tien']);
            $sheet->setCellValue("C$rowIndex", date('Y-m-d H:i:s', strtotime($plan['thoi_gian_bat_dau'])));
            $sheet->setCellValue("D$rowIndex", date('Y-m-d H:i:s', strtotime($plan['thoi_gian_ket_thuc'])));
            $sheet->setCellValue("E$rowIndex", date('Y-m-d', strtotime($plan['ngay_sx'])));
            $sheet->setCellValue("F$rowIndex", $plan['ca_sx']);
            $sheet->setCellValue("G$rowIndex", $plan['cong_doan_sx']);
            $sheet->setCellValue("H$rowIndex", $plan['machine_id']);
            $sheet->setCellValue("I$rowIndex", $plan['product_id']);
            $sheet->setCellValue("J$rowIndex", $plan['khach_hang']);
            $sheet->setCellValue("K$rowIndex", $plan['ten_san_pham']);
            $sheet->setCellValue("L$rowIndex", $plan['product_order_id']);
            $sheet->setCellValue("M$rowIndex", date('Y-m-d', strtotime($plan['delivery_date'])));
            $sheet->setCellValue("Q$rowIndex", $plan['sl_giao_sx']);
            $rowIndex++;
        }

        foreach (range('A', 'W') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
        $highestRow = $rowIndex - 1;
        $highestColumn = 'W';
        $sheet->getStyle("B3:$highestColumn$highestRow")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => '000000'],
                ],
            ],
        ]);

        $filePath = "exported_files/KHSX_output.xlsx";
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        return $this->success(url($filePath), 'Đã tạo file Excel thành công');
    }

    public function uploadProductionPlan(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '2048M');
        $extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $reader = match ($extension) {
            'csv' => new \PhpOffice\PhpSpreadsheet\Reader\Csv(),
            'xlsx' => new \PhpOffice\PhpSpreadsheet\Reader\Xlsx(),
            default => new \PhpOffice\PhpSpreadsheet\Reader\Xls()
        };

        $spreadsheet = $reader->load($_FILES['file']['tmp_name']);
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $productIds = array_unique(array_map(fn($row) => trim($row['I']), array_slice($allDataInSheet, 4)));
        $existingProductIds = Product::whereIn('id', $productIds)->pluck('id')->toArray();
        $missingProductIds = array_diff($productIds, $existingProductIds);

        if (!empty($missingProductIds)) {
            throw new Exception("Các mã sản phẩm sau chưa được khai báo " . implode(', ', $missingProductIds), 1);
        }

        DB::beginTransaction();
        try {
            $stepEndTimes = [];

            foreach ($allDataInSheet as $key => $row) {
                if ($key <= 3) continue;

                $machine = Machine::where('code', preg_replace('/\s+/', '', $row['H']))->first();
                if (!$machine) throw new Exception("Không tìm thấy máy " . $row['H']);

                $line = Line::find($machine->line_id);
                if (!$line) throw new Exception("Không tìm thấy công đoạn");

                $input = $this->mapInputData($row, $machine->line_id);
                $product = Product::find($input['product_id']);
                if (!$product) throw new Exception("Không tìm thấy mã sản phẩm " . $input['product_id']);

                $startTime = $input['thoi_gian_bat_dau'];

                $endTime = $this->calculateEndTime($input, $startTime, $product->id, $machine->line_id);

                $order = $this->getOrder($input, $product);
                $lo_sx = Losx::firstOrCreate(['product_order_id' => $order->id]);
                $losx_id = $lo_sx->id;
                $plan = $this->storeProductionPlan($input, $losx_id, $startTime, $endTime, $order, $line, $machine);
                $lastLotPLan = $this->generateLots($input, $losx_id, $plan, $startTime, $machine);
                if ($lastLotPLan) {
                    $plan->update(['thoi_gian_ket_thuc' => $lastLotPLan->end_time]);
                }
            }
            DB::commit();
        } catch (\Exception $ex) {
            DB::rollBack();
            return $this->failure([], $ex->getMessage(), 500);
        }
        return $this->success('', 'Đã tạo thành công');
    }

    private function storeProductionPlan($input, $losx_id, $startTime, $endTime, $order, $line, $machine)
    {
        $planInput = [
            'product_order_id' => $order->id,
            'ngay_dat_hang' => $order->order_date,
            'ngay_sx' => $startTime,
            'ngay_giao_hang' => $order->delivery_date,
            'line_id' => $line->id,
            'cong_doan_sx' => $line->name,
            'ca_sx' => 1,
            'delivery_date' => $order->delivery_date ? date('Y-m-d', strtotime($order->delivery_date)) : null,
            'machine_id' => $machine->code,
            'product_id' => $input['product_id'],
            'ten_san_pham' => $order->product->name ?? '',
            'khach_hang' => $order->customer->name ?? "",
            'lo_sx' => $losx_id,
            'thu_tu_uu_tien' => $input['thu_tu_uu_tien'],
            'nhan_luc' => $input['nhan_luc'],
            'tong_tg_thuc_hien' => $input['tong_tg_thuc_hien'],
            'thoi_gian_bat_dau' => $startTime,
            'thoi_gian_ket_thuc' => $endTime,
            'sl_giao_sx' => $input['sl_giao_sx'],
            'status_plan' => 0
        ];

        return ProductionPlan::create($planInput);
    }

    private function mapInputData($row, $lineId)
    {
        return [
            'product_order_id' => $row['L'],
            'ngay_dat_hang' => Carbon::parse(str_replace('/', '-', $row['AD']))->format('Y-m-d'),
            'cong_doan_sx' => Str::slug($row['G']),
            'line_id' => $lineId,
            'ca_sx' => $row['F'],
            'ngay_sx' => Carbon::parse(str_replace('/', '-', $row['E']))->format('Y-m-d'),
            'ngay_giao_hang' => Carbon::parse(str_replace('/', '-', $row['M']))->format('Y-m-d'),
            'machine_id' => preg_replace('/\s+/', '', $row['H']),
            'product_id' => trim($row['I']),
            'product_name' => $row['K'],
            'khach_hang' => $row['J'],
            'so_bat' => $row['T'] ?? 0,
            'sl_nvl' => $row['O'],
            'sl_tong_don_hang' => $row['N'],
            'sl_giao_sx' => filter_var($row['Q'], FILTER_SANITIZE_NUMBER_INT),
            'sl_thanh_pham' => $row['P'] ?? 0,
            'thu_tu_uu_tien' => $row['B'],
            'note' => $row['AE'] ?? "",
            'UPH' => str_replace(',', '', $row['W']),
            'nhan_luc' => $row['AB'],
            'tong_tg_thuc_hien' => filter_var($row['AA'], FILTER_SANITIZE_NUMBER_INT),
            'kho_giay' => $row['U'] ?? "",
            'toc_do' => $row['V'] ? (int)$row['V'] : "",
            'thoi_gian_chinh_may' => $row['X'] ? (float)$row['X'] : "",
            'thoi_gian_thuc_hien' => $row['Y'] ? (float)$row['Y'] : "",
            'thoi_gian_bat_dau' => Carbon::parse($row['E'] . ' ' . $row['C']),
            'thoi_gian_ket_thuc' => Carbon::parse($row['E'] . ' ' . $row['D'] . (strtotime($row['C']) > strtotime($row['D']) ? " +1 day" : "")),
            'status' => InfoCongDoan::STATUS_PLANNED
        ];
    }

    private function getStartTime($input, $stepEndTimes, $machineCode, $oldMachineCode)
    {
        // if ($oldMachineCode !== $machineCode) {
        //     return $stepEndTimes[$machineCode] ?? Carbon::parse($input['thoi_gian_bat_dau']);
        // }
        return Carbon::parse($input['thoi_gian_bat_dau']);
    }

    public function calculateEndTime($input, $startTime, $productId, $lineId)
    {
        $quantity = $input['sl_giao_sx'];
        $lotSize = $this->getLotSize($productId, $lineId);
        $taskTime = $this->getTaskTime($productId, $lineId, $input['UPH']);
        $numLots = ceil($quantity / $lotSize);
        $rollChangeTime = $this->getRollChangeTime($productId, $lineId);
        $setupTime = $this->getSetupTime($productId, $lineId);

        $endTime = $startTime->copy()->addMinutes(((($taskTime * $lotSize) + $rollChangeTime) * $numLots) + $setupTime);
        return $endTime;
    }
    public function getTaskTime($productId, $lineId, $uph)
    {
        $efficiency = $this->getEfficiency($productId, $lineId);
        return $efficiency > 0 ? (60 / $efficiency) : ($uph > 0 ? (60 / $uph) : 0);
    }

    private function getOrder($input, $product)
    {
        $customer = Customer::firstOrCreate(
            ['name' => $input['khach_hang']],
            ['name' => $input['khach_hang'], 'id' => Str::slug($input['khach_hang'])]
        );
        $id = QueryHelper::generateNewId(new ProductOrder(), date('Ym'), 2);
        return ProductOrder::firstOrCreate(
            ['id' => $input['product_order_id']],
            [
                'id' => $id,
                'order_number' => $id,
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'order_date' => $input['ngay_dat_hang'],
                'quantity' => $input['sl_thanh_pham'],
                'delivery_date' => $input['ngay_giao_hang']
            ]
        );
    }


    private function generateLots($input, $losx_id, $plan, $startTime, $machine)
    {
        $lot_plan = null;
        $quantity = $input['sl_giao_sx'];
        $lotSize = $this->getLotSize($input['product_id'], $input['line_id']);
        $numLots = ceil($quantity / $lotSize);
        $lotEndTime = $startTime;
        $taskTime = $this->getTaskTime($input['product_id'],  $input['line_id'], $input['UPH']);
        $rollChangeTime = $this->getRollChangeTime($input['product_id'],  $input['line_id']);
        $setupTime = $this->getSetupTime($input['product_id'],  $input['line_id']);
        for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
            $lotId =  $losx_id . '.L.' . str_pad($lotIndex, 4, '0', STR_PAD_LEFT);
            $lotStartTime = ($lotIndex == 1) ? $startTime : $lotEndTime;
            $quantityPerLot = ($lotIndex == 1 && ($quantity % $lotSize != 0)) ? ($quantity % $lotSize) : $lotSize;
            if ($lotIndex == 1) {
                $lotEndTime = $lotStartTime->copy()->addMinutes(($taskTime * $lotSize) + $rollChangeTime + $setupTime);
            } else {
                $lotEndTime = $lotStartTime->copy()->addMinutes(($taskTime * $lotSize) + $rollChangeTime);
            }
            if ($input['line_id'] == 29) {
                $lotEndTime = $lotStartTime->copy()->addMinutes(30);
            }
            $lot_plan = LotPlan::create([
                'lot_id' => $lotId,
                'lo_sx' => $losx_id,
                'line_id' => $input['line_id'],
                'product_id' => $input['product_id'],
                'machine_code' => $machine->code,
                'start_time' => $lotStartTime,
                'end_time' => $lotEndTime,
                'quantity' => $quantityPerLot,
                'lot_size' => $quantityPerLot,
                'product_order_id' => $plan->product_order_id,
                'customer_id' => $plan->product_order_id,
                'sl_giao_sx' => $quantityPerLot,
                'ca_sx' => 1,
                'cong_doan_sx' => $plan->cong_doan_sx,
                'machine_id' => $machine->code,
                'ten_san_pham' => $plan->ten_san_pham,
                'khach_hang' => $plan->khach_hang,
                'thoi_gian_bat_dau' => $lotStartTime,
                'thoi_gian_ket_thuc' => $lotEndTime,
                'production_plan_id' => $plan->id
            ]);
        }
        return $lot_plan;
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
}
