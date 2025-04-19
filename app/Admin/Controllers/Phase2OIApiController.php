<?php

namespace App\Admin\Controllers;

use App\Events\ProductionUpdated;
use App\Events\QualityUpdated;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Bom;
use App\Models\Cell;
use App\Models\CellLot;
use App\Models\CheckSheetLog;
use App\Models\Customer;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\ErrorHistory;
use App\Models\GroupYellowStamp;
use App\Models\GroupYellowStampInfo;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\LineInventories;
use App\Models\Lot;
use App\Models\LotErrorLog;
use App\Models\LotPlan;
use App\Models\Machine;
use App\Models\MachinePriorityOrder;
use App\Models\MachineStatus;
use App\Models\Material;
use App\Models\NGTracking;
use App\Models\OddBin;
use App\Models\Product;
use App\Models\ProductionOrderHistory;
use App\Models\ProductionPlan;
use App\Models\QCHistory;
use App\Models\RollMaterial;
use App\Models\Spec;
use App\Models\TestCriteria;
use App\Models\TestCriteriaDetailHistory;
use App\Models\TestCriteriaHistory;
use App\Models\Tracking;
use App\Models\WareHouseExportPlan;
use App\Models\WareHouseLog;
use App\Models\YellowStampHistory;
use App\Traits\API;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use stdClass;

class Phase2OIApiController extends Controller
{
    use API;
    //Cập nhật dữ liệu sản xuất từ IOT
    public function updateProduction(Request $request)
    {
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
            } else if ($status == 1) { // chạy hàng loạt
                if (!isset($info_cong_doan->sl_dau_vao_hang_loat)) $info_cong_doan->sl_dau_vao_hang_loat = 0;
                $info_cong_doan->sl_dau_vao_hang_loat += $d_input;
                if (!isset($info_cong_doan->sl_dau_ra_hang_loat)) $info_cong_doan->sl_dau_ra_hang_loat = 0;
                $info_cong_doan->sl_dau_ra_hang_loat += $d_output;
            }
            $info_cong_doan->save();
            broadcast(new ProductionUpdated($info_cong_doan))->toOthers();
        }
        return response()->json(['message' => 'Equipment quantity updated successfully'], 200);
    }
    //==================================Sản xuất==================================
    //Trả về danh sách công đoạn theo nhà máy
    public function getLineList(Request $request)
    {
        $query = Line::where("display", "1")
            ->where('factory_id', 2)
            ->orderBy('ordering', 'ASC');
        if (isset($request->line_id)) {
            $query = $query->where('id', '<>', $request->line_id);
        }
        $list = $query->get();
        $except = [
            'sx' => ['kho-thanh-pham', 'oqc', 'iqc'],
            'cl' => ['kho-thanh-pham', 'kho-bao-on', 'u']
        ];
        $data = [];
        if (isset($request->type)) {
            if ($request->type === 'tb') {
                foreach ($list as $item) {
                    if (count($item->machine()->where('display', '1')->get()) > 0) {
                        $data[] = [
                            "label" => $item->name,
                            "ordering" => $item->ordering,
                            "value" => $item->id
                        ];
                    }
                }
            } else {
                foreach ($list as $item) {
                    $line_key = Str::slug($item->name);
                    if (in_array($line_key, $except[$request->type])) {
                        continue;
                    }
                    $data[] = [
                        "label" => $item->name,
                        "ordering" => $item->ordering,
                        "value" => $item->id
                    ];
                }
            }
        } else {
            foreach ($list as $item) {
                $data[] = [
                    "label" => $item->name,
                    "ordering" => $item->ordering,
                    "value" => $item->id
                ];
            }
        }
        return $this->success($data);
    }
    //Trả về danh sách máy theo dây chuyền
    public function getMachineList(Request $request)
    {
        $query = Machine::select('id', 'code', 'name', 'is_iot');
        if (isset($request->line)) {
            $line = Line::with(['machine:id,code,name,line_id'])->find($request->line);
            $query->where('line_id', $line->id);
        } else {
            $line_id = Line::where('factory_id', 2)->pluck('id')->toArray();
            $query->whereIn('line_id', $line_id);
        }
        $machine = $query->orderBy('line_id')->orderBy('code')->get()->sortBy('code', SORT_NATURAL)->values();
        return $this->success($machine);
    }

    //Trả về dữ liệu tổng quan của sản xuất
    public function getProductionOverall(Request $request)
    {
        $info_query = InfoCongDoan::whereDate("created_at", Carbon::now());
        if (!empty($request->machine_code)) {
            $info_query->where('machine_code', $request->machine_code);
        }
        if (!empty($request->line_id)) {
            $info_query->where('line_id', $request->line_id);
        }
        $info_cong_doans = $info_query->get();

        $plan = ProductionPlan::whereDate("ngay_sx", Carbon::now());
        if (!empty($request->machine_code)) {
            $plan->where('machine_id', $request->machine_code);
        }
        if (!empty($request->line_id)) {
            $plan->where('line_id', $request->line_id);
        }
        $plans = $plan->get();
        $data =  [
            "tong_sl_trong_ngay_kh" => $plans->sum('sl_giao_sx'),
            "tong_sl_thuc_te" =>  $info_cong_doans->sum('sl_dau_ra_hang_loat'),
            "tong_sl_tem_vang" =>  $info_cong_doans->sum('sl_tem_vang'),
            "tong_sl_ng" => $info_cong_doans->sum('sl_ng'),
        ];
        $data['ty_le_hoan_thanh'] = $data['tong_sl_trong_ngay_kh'] > 0 ? round($data['tong_sl_thuc_te'] / $data['tong_sl_trong_ngay_kh'] * 100) . "%" : "%";
        return $this->success($data);
    }

    //Trả về sanh sách Lot sản xuất của công đoạn
    public function parseLotPlanData($lotPlans)
    {
        $records = [];
        foreach ($lotPlans as $item) {
            $hao_phi_sx = $item->spec->first(function ($record) {
                return $record->name == 'Hao phí sản xuất các công đoạn (%)';
            }) ?? null;
            $hao_phi_vao_hang = $item->spec->first(function ($record) {
                return $record->name == 'Hao phí vào hàng các công đoạn';
            }) ?? null;
            $infoCongDoan = $item->infoCongDoan;
            $data =  [
                "lo_sx" => $item->lo_sx,
                "input_lot_id" => $infoCongDoan->input_lot_id ?? '',
                "lot_id" => $item->lot_id,
                "ma_hang" => $item->product->id ?? '',
                "ten_sp" => $item->product->name ?? '',
                "sl_ke_hoach" => $item->quantity ?? 0,
                'thoi_gian_bat_dau_kh' => $item->start_time ? date('d/m/Y H:i:s', strtotime($item->start_time)) : "",
                "thoi_gian_ket_thuc_kh" => $item->end_time ? date('d/m/Y H:i:s', strtotime($item->end_time)) : "",
                'thoi_gian_bat_dau' => isset($infoCongDoan->thoi_gian_bat_dau) ? date('d/m/Y H:i:s', strtotime($infoCongDoan->thoi_gian_bat_dau)) : "",
                'thoi_gian_ket_thuc' => isset($infoCongDoan->thoi_gian_ket_thuc) ? date('d/m/Y H:i:s', strtotime($infoCongDoan->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => $item->quantity ?? 0,
                'sl_dau_ra_kh' => $item->quantity ?? 0,
                'sl_dau_vao_hang_loat' => $infoCongDoan->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra_hang_loat' => $infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_ra_ok" => ($infoCongDoan->sl_dau_ra_hang_loat ?? 0) - ($infoCongDoan->sl_tem_vang ?? 0) - ($infoCongDoan->sl_ng ?? 0),
                "sl_tem_vang" => $infoCongDoan->sl_tem_vang ?? 0,
                "sl_ng" => $infoCongDoan->sl_ng ?? 0,
                "uph_an_dinh" => $item->plan->UPH ?? 0,
                "uph_thuc_te" => 0,
                "status" => $infoCongDoan->status ?? InfoCongDoan::STATUS_PLANNED,
                "thoi_gian_bam_may" => isset($infoCongDoan->thoi_gian_bam_may) ? date('d/m/Y H:i:s', strtotime($infoCongDoan->thoi_gian_bam_may)) : "",
                'hao_phi_cong_doan' => $hao_phi_sx ? $hao_phi_sx->value . "%" : "",
                'sl_dau_vao' => $infoCongDoan->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra' => $infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                'sl_tem_ng' => $infoCongDoan->sl_ng ?? 0,
                'is_qc' => ($infoCongDoan && !is_null($infoCongDoan->qcHistory)) ? $infoCongDoan->qcHistory->eligible_to_end : 0,
                'is_assign' => $infoCongDoan && count($infoCongDoan->assignments ?? []) > 0 ? 1 : 0,
                'info_id' => $infoCongDoan->id ?? null,
            ];
            $data['ti_le_ht'] = $item->quantity > 0 ? round($data['sl_dau_ra_ok'] / $item->quantity * 100) . '%' : "0%";
            $data['hao_phi'] = $data['sl_dau_vao'] ? round((($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) > 0 ? ($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) : 0 / $data['sl_dau_vao']) * 100) . '%' : "";
            $records[] = $data;
        }
        return $records;
    }

    public function parseInfoData($infoList)
    {
        $records = [];
        foreach ($infoList as $item) {
            $hao_phi_sx = $item->spec->first(function ($record) {
                return $record->name == 'Hao phí sản xuất các công đoạn (%)';
            }) ?? null;
            $hao_phi_vao_hang = $item->spec->first(function ($record) {
                return $record->name == 'Hao phí vào hàng các công đoạn';
            }) ?? null;
            $infoCongDoan = $item;
            $data =  [
                "lo_sx" => $item->lo_sx,
                "input_lot_id" => $infoCongDoan->input_lot_id ?? '',
                "lot_id" => $item->lot_id,
                "ma_hang" => $item->product->id ?? '',
                "ten_sp" => $item->product->name ?? '',
                "sl_ke_hoach" => $item->quantity ?? 0,
                'thoi_gian_bat_dau_kh' => isset($item->lotPlan->start_time) ? date('d/m/Y H:i:s', strtotime($item->lotPlan->start_time)) : "",
                "thoi_gian_ket_thuc_kh" => isset($item->lotPlan->end_time) ? date('d/m/Y H:i:s', strtotime($item->lotPlan->end_time)) : "",
                'thoi_gian_bat_dau' => isset($infoCongDoan->thoi_gian_bat_dau) ? date('d/m/Y H:i:s', strtotime($infoCongDoan->thoi_gian_bat_dau)) : "",
                'thoi_gian_ket_thuc' => isset($infoCongDoan->thoi_gian_ket_thuc) ? date('d/m/Y H:i:s', strtotime($infoCongDoan->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => $item->lotPlan->quantity ?? 0,
                'sl_dau_ra_kh' => $item->lotPlan->quantity ?? 0,
                'sl_dau_vao_hang_loat' => $infoCongDoan->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra_hang_loat' => $infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_ra_ok" => ($infoCongDoan->sl_dau_ra_hang_loat ?? 0) - ($infoCongDoan->sl_tem_vang ?? 0) - ($infoCongDoan->sl_ng ?? 0),
                "sl_tem_vang" => $infoCongDoan->sl_tem_vang ?? 0,
                "sl_ng" => $infoCongDoan->sl_ng ?? 0,
                "uph_an_dinh" => $item->plan->UPH ?? 0,
                "uph_thuc_te" => 0,
                "status" => $infoCongDoan->status ?? InfoCongDoan::STATUS_PLANNED,
                "thoi_gian_bam_may" => isset($infoCongDoan->thoi_gian_bam_may) ? date('d/m/Y H:i:s', strtotime($infoCongDoan->thoi_gian_bam_may)) : "",
                'hao_phi_cong_doan' => $hao_phi_sx ? $hao_phi_sx->value . "%" : "",
                'sl_dau_vao' => $infoCongDoan->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra' => $infoCongDoan->sl_dau_ra_hang_loat ?? 0,
                'sl_tem_vang' => $infoCongDoan->sl_tem_vang ?? 0,
                'sl_tem_ng' => $infoCongDoan->sl_ng ?? 0,
                'is_qc' => ($infoCongDoan && !is_null($infoCongDoan->qcHistory)) ? $infoCongDoan->qcHistory->eligible_to_end : 0,
                'is_assign' => $infoCongDoan && count($infoCongDoan->assignments ?? []) > 0 ? 1 : 0,
                'info_id' => $infoCongDoan->id ?? null,
            ];
            $data['ti_le_ht'] = $data['sl_dau_vao_kh'] > 0 ? round($data['sl_dau_ra_ok'] / $data['sl_dau_vao_kh'] * 100) . '%' : "0%";
            $data['sl_dau_ra_ok'] = $data['sl_dau_ra'] - $data['sl_tem_vang'] - $data['sl_tem_ng'];
            $data['hao_phi'] = $data['sl_dau_vao'] ? round((($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) > 0 ? ($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) : 0 / $data['sl_dau_vao']) * 100) . '%' : "";
            $records[] = $data;
        }
        return $records;
    }

    public function getLotProductionList(Request $request)
    {
        $line_id = $request->line_id;
        $machine_code = $request->machine_code;
        $date  = date('Y-m-d');
        $lot_plan_query = LotPlan::orderBy('created_at', 'ASC')->orderBy('lo_sx', 'ASC')->orderBy('start_time', 'ASC');
        $info_query = InfoCongDoan::query();
        if (!empty($request->line_id)) {
            $lot_plan_query->where('line_id', $line_id);
            $info_query->where('line_id', $line_id);
        }
        if (!empty($request->machine_code)) {
            $lot_plan_query->where('machine_code', $machine_code);
            $info_query->where('machine_code', $machine_code);
        }
        $lotPlans = $lot_plan_query->where(function ($query) use ($date) {
            $query->where(function ($que) use ($date) {
                $que->whereDate('start_time', $date)->whereHas('plan', function ($q) {
                    $q->whereIn('status_plan', [0, 1]);
                });
            })->orWhereHas('infoCongDoan', function ($qu) {
                $qu->where('status', 1);
            });
        })->with('infoCongDoan.qcHistory', 'spec', 'plan', 'infoCongDoan.assignments')->get();
        $lotPlanList = $this->parseLotPlanData($lotPlans);
        $infos = $info_query->whereHas('lot', function ($q) {
            $q->where('type', '!=', Lot::TYPE_TEM_TRANG);
        })->whereDate('created_at', $date)->where('status', '>', 1)->with('qcHistory', 'spec', 'plan', 'assignments', 'lotPlan')->get();
        $infoList = $this->parseInfoData($infos);
        $records = array_merge($infoList ?? [], $lotPlanList ?? []);
        return $this->success($records);
    }

    //=============================OI Sản xuất phiên bản mới=============================
    //Bottom table
    public function oiProductionList(Request $request)
    {
        $line_id = $request->line_id;
        $machine_code = $request->machine_code;
        $info_query = InfoCongDoan::whereNotNull('plan_id')
            ->orderBy('thoi_gian_bat_dau', 'DESC')
            ->where(function ($query) {
                $query->whereDate('thoi_gian_bat_dau', date('Y-m-d'))->orWhere('status', InfoCongDoan::STATUS_INPROGRESS);
            });
        if (!empty($request->line_id)) {
            $info_query->where('line_id', $line_id);
        }
        if (!empty($request->machine_code)) {
            $info_query->where('machine_code', $machine_code);
        }
        $infos = $info_query->get();
        foreach ($infos as $key => $info) {
            $plan = $info->plan;
            $product_name = $info->product->name ?? "";
            if ($info->line_id == 24) {
                $material = Material::find($info->product_id);
                if ($material) {
                    $product_name = $material->name ?? "";
                }
            }
            $info->ten_sp = $product_name;
            $info->ma_hang = $info->product_id;
            $info->thoi_gian_bat_dau_kh = ($plan && $plan->thoi_gian_bat_dau) ? Carbon::parse($plan->thoi_gian_bat_dau)->format('d/m/Y H:i:s') : '';
            $info->thoi_gian_ket_thuc_kh = ($plan && $plan->thoi_gian_bat_dau) ? Carbon::parse($plan->thoi_gian_ket_thuc)->format('d/m/Y H:i:s') : '';
            $info->sl_dau_ra_kh = $info->sl_kh;
            $info->thoi_gian_bat_dau = $info->thoi_gian_bat_dau ? Carbon::parse($info->thoi_gian_bat_dau)->format('d/m/Y H:i:s') : "";
            $info->thoi_gian_ket_thuc = $info->thoi_gian_ket_thuc ? Carbon::parse($info->thoi_gian_ket_thuc)->format('d/m/Y H:i:s') : "";
            $info->sl_dau_vao_chay_thu = $info->sl_dau_vao_chay_thu ?? 0;
            $info->sl_dau_vao_hang_loat = $info->sl_dau_vao_hang_loat ?? 0;
            $info->sl_dau_ra_chay_thu = $info->sl_dau_ra_chay_thu ?? 0;
            $info->sl_dau_ra_hang_loat = $info->sl_dau_ra_hang_loat ?? 0;
            $info->sl_tem_vang = $info->sl_tem_vang ?? 0;
            $info->sl_tem_ng = $info->sl_ng ?? 0;
            $info->sl_dau_ra_ok = $info->sl_dau_ra_hang_loat - $info->sl_ng - $info->sl_tem_vang;
            $info->ti_le_ht = $plan && $plan->sl_giao_sx > 0 ? round($info->sl_dau_ra_ok / $plan->sl_giao_sx * 100) : 0;
            $info->is_qc = (!is_null($info->qcHistory)) ? $info->qcHistory->eligible_to_end : 0;
            $info->is_assign = count($info->assignments ?? []) > 0 ? 1 : 0;
            $info->uph_an_dinh = $plan->UPH ?? 0;
            $info->uph_thuc_te = 0;

            $hao_phi_sx = $info->spec->first(function ($record) {
                return $record->name == 'Hao phí sản xuất các công đoạn (%)';
            }) ?? null;
            $info->hao_phi_cong_doan = ($hao_phi_sx->value ?? 0) . '%';
            $hao_phi_vao_hang = $info->spec->first(function ($record) {
                return $record->name == 'Hao phí vào hàng các công đoạn';
            }) ?? null;
            $hao_phi = ($info->sl_ng);
            $info->hao_phi = ($info->sl_dau_ra_hang_loat ? round(($hao_phi / $info->sl_dau_ra_hang_loat) * 100) : 0) . '%';

            $info->so_dau_noi = LotErrorLog::where('lot_id', $info->lot_id)->count();
        }
        return $this->success($infos);
    }

    function updateAndReorderMachinePriorities($machineId, $productId, $lineId)
    {
        $machinePriority = MachinePriorityOrder::where('machine_id', $machineId)->where('product_id', $productId)->where('line_id', $lineId)->first();
        if ($machinePriority) {
            $machinePriority->update(['priority' => 1]);
            $list = MachinePriorityOrder::where('machine_id', '!=', $machineId)->where('product_id', $productId)->where('line_id', $lineId)->orderBy('priority')->get();
            foreach ($list as $key => $value) {
                $value->update(['priority' => $key + 2]);
            }
        }
    }

    public function scanForFirstLine(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $isExist = InfoCongDoan::where('machine_code', $machine->code)->where('status', 1)->first();
        if ($isExist) {
            return $this->failure('', 'Có lot chưa hoàn thành, không thể tiếp tục lot khác');
        }
        if ($machine->is_iot) {
            $checksheet_logs = CheckSheetLog::where('info->machine_id', $machine->code)->whereDate('created_at', Carbon::today())->get();
            if (count($checksheet_logs) <= 0) {
                return $this->failure([], "Chưa nhập kiểm tra checksheet");
            }
            $tracking = Tracking::where('machine_id', $machine->code)->first();
            if (!$tracking) {
                $tracking = Tracking::create([
                    'machine_id' => $machine->code,
                    'input' => 0,
                    'output' => 0
                ]);
            }
        }
        $plan = ProductionPlan::where('line_id', $machine->line_id)
            ->where('machine_id', $machine->code)
            ->whereIn('status_plan', [ProductionPlan::STATUS_PENDING, ProductionPlan::STATUS_IN_PROGRESS])
            ->whereDate('thoi_gian_bat_dau', date('Y-m-d'))
            ->orderBy('status_plan', 'DESC')
            ->orderBy('thoi_gian_bat_dau')
            ->first();
        if (!$plan) {
            return $this->failure([], 'Không tìm thấy KHSX');
        }
        $checker = Bom::where('product_id', $plan->product_id)->whereColumn('product_id', '=', 'material_id')->get();
        if (count($checker) === 0) {
            $roll = RollMaterial::with(['material.products', 'warehouse_inventory'])->find($request->roll_id);
            if (!$roll) {
                return $this->failure([], "Không tìm thấy cuộn");
            }
            // if (!$roll->warehouse_inventory || $roll->warehouse_inventory->quantity <= 0) {
            //     return $this->failure([], "Cuộn đã quét rồi");
            // }
            // if (!$roll->material) {
            //     return $this->failure([], "Không tìm thấy NVL: " . ($roll->material_id ?? ""));
            // }
            $product_ids = $roll->material->products->pluck('id')->toArray() ?? [];
            // if (count($product_ids) === 0) {
            //     return $this->failure([], "Không tìm thấy sản phẩm");
            // }
            $product_ids[] = $roll->material_id;

            if (!in_array($plan->product_id, $product_ids)) {
                return $this->failure([], "Mã cuộn không phù hợp");
            }
        }

        try {
            DB::beginTransaction();
            if ($plan->status_plan == ProductionPlan::STATUS_PENDING) {
                $plan->update(['status_plan' => ProductionPlan::STATUS_IN_PROGRESS]);
            }
            // if($roll->warehouse_inventory){
            //     $roll->warehouse_inventory->update(['quantity' => 0]);
            // }
            MachineStatus::reset($machine->code);
            $info = InfoCongDoan::firstOrCreate(
                ['lot_id' => InfoCongDoan::generateUniqueId($plan->lo_sx, $plan->line_id), 'plan_id' => $plan->id, 'line_id' => $machine->line_id, 'machine_code' => $machine->code],
                [
                    'input_lot_id' => $request->roll_id,
                    'lo_sx' => $plan->lo_sx,
                    'product_id' => $plan->product_id,
                    'component_id' => $plan->component_id,
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_INPROGRESS,
                    'user_id' => $request->user()->id,
                    'sl_kh' => $plan->sl_giao_sx,
                    'plan_id' => $plan->id
                ]
            );
            if (isset($tracking)) {
                $tracking->update([
                    'lot_id' => $info->lot_id,
                    'input' => 0,
                    'output' => 0
                ]);
            }

            $this->updateAndReorderMachinePriorities($machine->code, $plan->product_id, $machine->line_id);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th->getMessage(), "Lỗi quét tem");
        }
        return $this->success([], "Bắt đầu sản xuất");
    }

    public function scanForProductionLine(Request $request)
    {
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $isExist = InfoCongDoan::where('machine_code', $machine->code)->where('status', 1)->first();
        if ($isExist) {
            return $this->failure('', 'Có lot chưa hoàn thành, không thể tiếp tục lot khác');
        }
        if ($machine->is_iot) {
            $checksheet_logs = CheckSheetLog::where('info->machine_id', $machine->code)->whereDate('created_at', Carbon::today())->get();
            if (count($checksheet_logs) <= 0) {
                return $this->failure([], "Chưa nhập kiểm tra checksheet");
            }
            $tracking = Tracking::where('machine_id', $machine->code)->first();
            if ($machine->is_iot == 1 && !$tracking) {
                return $this->failure([], "Máy này chưa được sử dụng");
            }
            if ($tracking->lot_id && $tracking->lot_id !== $request->lot_id) {
                return $this->failure([], "Máy này đang sản xuất lot khác");
            }
        }
        if ($machine->line_id != 26) {
            $scannedLot = Lot::find($request->scanned_lot);
            if (!$scannedLot) {
                return $this->failure('', 'Không tìm thấy lot');
            }
            // $checkInfo = InfoCongDoan::where('input_lot_id', $request->scanned_lot)->first();
            // if ($checkInfo && $machine->line_id != 24) {
            //     return $this->failure('', 'Lot đã được sử dụng');
            // }
            $plan_query = ProductionPlan::where('line_id', $machine->line_id)
                ->where('machine_id', $machine->code)
                ->whereIn('status_plan', [ProductionPlan::STATUS_PENDING, ProductionPlan::STATUS_IN_PROGRESS])
                ->whereDate('thoi_gian_bat_dau', date('Y-m-d'))
                ->orderBy('status_plan', 'DESC')
                ->orderBy('thoi_gian_bat_dau');
            $hanh_trinh_san_xuat = Spec::where('slug', 'hanh-trinh-san-xuat')->where('product_id', $scannedLot->product_id)->whereRaw('value REGEXP "^[0-9]+$"')->orderBy('value')->pluck('value', 'line_id');
            $requestValue = $hanh_trinh_san_xuat[$request->line_id] ?? 0;
            // Lọc các line_id có value nhỏ hơn requestValue
            $filteredLineIds = collect($hanh_trinh_san_xuat)
                ->filter(function ($value, $lineId) use ($requestValue) {
                    return $value < $requestValue;
                })->keys();
            $orderByString = "'" . implode("','", $filteredLineIds->toArray()) . "'";
            $previousLineLot = InfoCongDoan::where('lot_id', $request->scanned_lot)
                ->whereIn('line_id', $filteredLineIds)->where('status', InfoCongDoan::STATUS_COMPLETED)
                ->orderByRaw("FIELD(line_id, $orderByString)")
                ->get()
                ->last();
            if (count($filteredLineIds) > 0) {
                if (!$previousLineLot) {
                    return $this->failure([], 'Không tìm thấy lot đã chạy trước đó');
                }
                if ($previousLineLot->line_id == 24) {
                    $bomProducts = Bom::where(function ($subQuery) use ($previousLineLot) {
                        $subQuery->where('material_id', $previousLineLot->product_id)->orWhere('product_id', $previousLineLot->product_id);
                    })->pluck('product_id')->toArray();
                    if (!in_array($scannedLot->product_id, $bomProducts)) {
                        return $this->failure($previousLineLot, 'Không khớp mã sản phẩm');
                    } else {
                        $plan_query->whereIn('product_id', $bomProducts);
                    }
                } else {
                    if ($previousLineLot->product_id !== $scannedLot->product_id) {
                        return $this->failure([$previousLineLot, $scannedLot], 'Không khớp mã sản phẩm');
                    } else {
                        $plan_query->where('product_id', $previousLineLot->product_id);
                    }
                }
            }
            $plan = $plan_query->first();
            if (!$plan) {
                return $this->failure([], 'Không tìm thấy KHSX');
            }
        } else {
            $scannedLot = Lot::find($request->scanned_lot);
            $plan_query = ProductionPlan::where('line_id', $machine->line_id)
                ->where('machine_id', $machine->code)
                ->whereIn('status_plan', [ProductionPlan::STATUS_PENDING, ProductionPlan::STATUS_IN_PROGRESS])
                ->whereDate('thoi_gian_bat_dau', date('Y-m-d'))
                ->orderBy('status_plan', 'DESC')
                ->orderBy('thoi_gian_bat_dau');
            // if ($scannedLot) {
            //     $plan_query->where('product_id', $scannedLot->product_id);
            // }
            $plan = $plan_query->first();
            if (!$plan) {
                return $this->failure([], 'Không tìm thấy KHSX');
            }
        }
        try {
            DB::beginTransaction();
            MachineStatus::reset($machine->code);
            if ($plan->status_plan == ProductionPlan::STATUS_PENDING) {
                $plan->update(['status_plan' => ProductionPlan::STATUS_IN_PROGRESS]);
            }
            $infoCongDoan = InfoCongDoan::create([
                'lot_id' => InfoCongDoan::generateUniqueId($plan->lo_sx, $machine->line_id),
                'line_id' => $machine->line_id,
                'machine_code' => $machine->code,
                'input_lot_id' => $request->scanned_lot,
                'lo_sx' => $plan->lo_sx,
                'product_id' => $plan->product_id,
                'thoi_gian_bat_dau' => Carbon::now(),
                'status' => InfoCongDoan::STATUS_INPROGRESS,
                'user_id' => $request->user()->id,
                'sl_kh' => $plan->sl_giao_sx,
                'plan_id' => $plan->id
            ]);
            if ($machine->code != 'IN_8_MAU_01' && $machine->code != 'DC_1' && $machine->line_id != 26) {
                if ($scannedLot) {
                    $sl_dat = $scannedLot->so_luong;
                    $line_inventory = LineInventories::where('product_id', $scannedLot->product_id)->where('line_id', $scannedLot->final_line_id)->first();

                    if ($line_inventory) {
                        $line_inventory->update(['quantity' => $line_inventory->quantity - $sl_dat]);
                    } else {
                        LineInventories::create(['quantity' => $sl_dat, 'line_id' => $infoCongDoan->line_id, 'product_id' => $infoCongDoan->product_id]);
                    }
                }
            }
            if (isset($tracking)) {
                $tracking->update([
                    'lot_id' => $infoCongDoan->lot_id,
                    'input' => 0,
                    'output' => 0
                ]);
            }
            $this->updateAndReorderMachinePriorities($machine->code, $plan->product_id, $machine->line_id);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
            return $this->failure($th, 'Lỗi quét tem' . $th);
        }
        return $this->success([], "Quét lot thành công");
    }

    public function scanForSelectionLineV2(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $scannedLot = Lot::find($request->scanned_lot);
        // if(!$scannedLot){
        //     return $this->failure('', 'Không tìm thấy lot');
        // }
        $check = InfoCongDoan::where('machine_code', $machine->code)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($check) {
            return $this->failure([], "Chưa hoàn thành lot trước đó");
        }
        $plan = ProductionPlan::where('line_id', $machine->line_id)
            ->where('machine_id', $machine->code)
            ->whereIn('status_plan', [ProductionPlan::STATUS_PENDING, ProductionPlan::STATUS_IN_PROGRESS])
            ->whereDate('thoi_gian_bat_dau', '>=', date('Y-m-d'))
            ->orderBy('status_plan', 'DESC')
            ->orderBy('thoi_gian_bat_dau')
            ->first();
        if (!$plan) {
            return $this->failure([], 'Không tìm thấy KHSX');
        }
        $hanh_trinh_san_xuat = Spec::where('slug', 'hanh-trinh-san-xuat')->where('product_id', $plan->product_id)->whereRaw('value REGEXP "^[0-9]+$"')->orderBy('value')->pluck('value', 'line_id');
        $requestValue = $hanh_trinh_san_xuat[$request->line_id] ?? 0;
        // Lọc các line_id có value nhỏ hơn requestValue
        $filteredLineIds = collect($hanh_trinh_san_xuat)
            ->filter(function ($value, $lineId) use ($requestValue) {
                return $value < $requestValue;
            })->keys();
        $orderByString = "'" . implode("','", $filteredLineIds->toArray()) . "'";
        // $previousLineLot = InfoCongDoan::where('lot_id', $request->scanned_lot)
        //     ->whereIn('line_id', $filteredLineIds)->where('status', InfoCongDoan::STATUS_COMPLETED)
        //     ->orderByRaw("FIELD(line_id, $orderByString)")
        //     ->get()
        //     ->last();
        $so_luong = $scannedLot->so_luong ?? 11000;
        // if (count($filteredLineIds) > 0) {
        //     if (!$previousLineLot) {
        //         return $this->failure([], 'Không tìm thấy lot đã chạy trước đó');
        //     }
        //     if ($previousLineLot->line_id == 24) {
        //         $bomProducts = Bom::where(function ($subQuery) use ($previousLineLot) {
        //             $subQuery->where('material_id', $previousLineLot->product_id)->orWhere('product_id', $previousLineLot->product_id);
        //         })->pluck('product_id')->toArray();
        //         if (!in_array($plan->product_id, $bomProducts)) {
        //             return $this->failure($previousLineLot, 'Không khớp mã sản phẩm');
        //         }
        //     } else {
        //         if ($previousLineLot->product_id !== $plan->product_id) {
        //             return $this->failure([$previousLineLot, $plan], 'Không khớp mã sản phẩm');
        //         }
        //     }
        // }

        try {
            DB::beginTransaction();
            if ($plan->status_plan == ProductionPlan::STATUS_PENDING) {
                $plan->update(['status_plan' => ProductionPlan::STATUS_IN_PROGRESS]);
            }
            $infoCongDoan = InfoCongDoan::create([
                'lot_id' => InfoCongDoan::generateUniqueId($plan->lo_sx, $machine->line_id),
                'line_id' => $machine->line_id,
                'machine_code' => $machine->code,
                'input_lot_id' => $request->scanned_lot,
                'lo_sx' => $plan->lo_sx,
                'product_id' => $plan->product_id,
                'thoi_gian_bat_dau' => Carbon::now(),
                'sl_dau_vao_hang_loat' => $so_luong,
                'sl_dau_ra_hang_loat' => $so_luong,
                'status' => InfoCongDoan::STATUS_INPROGRESS,
                'user_id' => $request->user()->id,
                'sl_kh' => $so_luong,
                'plan_id' => $plan->id
            ]);
            if ($scannedLot) {
                $sl_dat = $scannedLot->so_luong;
                $line_inventory = LineInventories::where('product_id', $scannedLot->product_id)->where('line_id', $scannedLot->final_line_id)->first();
                if ($line_inventory) {
                    $line_inventory->update(['quantity' => $line_inventory->quantity - $sl_dat]);
                } else {
                    LineInventories::create(['quantity' => $sl_dat, 'line_id' => $infoCongDoan->line_id, 'product_id' => $infoCongDoan->product_id]);
                }
            }
            if (isset($tracking)) {
                $tracking->update([
                    'lot_id' => $infoCongDoan->lot_id,
                    'input' => 0,
                    'output' => 0
                ]);
            }
            $this->updateAndReorderMachinePriorities($machine->code, $plan->product_id, $machine->line_id);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi quét lot");
        }
        return $this->success([], "Bắt đầu sản xuất");
    }

    public function finishProductionLine(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if ($machine->is_iot == 1 && !$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();

        if ($infoCongDoan) {
            if (!$infoCongDoan->qcHistory) {
                return $this->failure([], 'Chưa kiểm tra QC');
            }
            if (!$this->checkEligibleForPrinting($infoCongDoan)) {
                return $this->failure([], "Chưa kiểm tra đủ tiêu chí QC");
            }
            try {
                DB::beginTransaction();
                $counter = $this->fetchDataFromApi($machine->device_id);
                if ($machine->is_iot == 1 && $counter['PLC:Num_Out'][0]['value'] && $counter['PLC:Num_Out'][0]['value'] - $tracking->output > 0) {
                    $sl_dau_ra_hang_loat = ($counter['PLC:Num_Out'][0]['value'] - $tracking->output) * ($infoCongDoan->product->so_bat ?? 1);
                } else {
                    $sl_dau_ra_hang_loat = $infoCongDoan->sl_dau_ra_hang_loat;
                }
                if ($machine->is_iot == 1 && $counter['PLC:Num_Out'][0]['ts'] && $machine->is_iot == 1) {
                    $thoi_gian_ket_thuc = $this->formatTimestampWithTimezone($counter['PLC:Num_Out'][0]['ts']);
                } else {
                    $thoi_gian_ket_thuc = Carbon::now();
                }
                if ($machine->is_iot == 1) {
                    $infoCongDoan->update([
                        'thoi_gian_ket_thuc' => $thoi_gian_ket_thuc,
                        'sl_dau_ra_hang_loat' => $sl_dau_ra_hang_loat,
                        'sl_dau_ra_ket_thuc' => $counter['PLC:Num_Out'][0]['value'] ?? 0,
                        'sl_dau_vao_ket_thuc' => $counter['PLC:Num_Input'][0]['value'] ?? 0,
                        'status' => InfoCongDoan::STATUS_COMPLETED
                    ]);
                } else {
                    $infoCongDoan->update([
                        'thoi_gian_ket_thuc' => $thoi_gian_ket_thuc,
                        'sl_dau_ra_hang_loat' => $sl_dau_ra_hang_loat,
                        'status' => InfoCongDoan::STATUS_COMPLETED
                    ]);
                }
                $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
                if ($sl_con_lai < 0) {
                    return $this->failure([], "Số lượng sản xuất không hợp lệ");
                }
                $lot = Lot::find($infoCongDoan->lot_id);
                if (!$lot) {
                    Lot::create([
                        'id' => $infoCongDoan->lot_id,
                        'product_id' => $infoCongDoan->product_id,
                        'material_id' => $infoCongDoan->material_id,
                        'lo_sx' => $infoCongDoan->lo_sx,
                        'final_line_id' => $line->id,
                        'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang,
                        'type' => Lot::TYPE_TEM_TRANG
                    ]);
                } else {
                    $lot->update([
                        'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang,
                        'final_line_id' => $line->id,
                    ]);
                }
                $sl_dat = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng;
                $line_inventory = LineInventories::where('product_id', $infoCongDoan->product_id)->where('line_id', $infoCongDoan->line_id)->first();
                if ($line_inventory) {
                    $line_inventory->update(['quantity' => $line_inventory->quantity + $sl_dat]);
                } else {
                    LineInventories::create(['quantity' => $sl_dat, 'line_id' => $infoCongDoan->line_id, 'product_id' => $infoCongDoan->product_id]);
                }
                if ($infoCongDoan->plan) {
                    //Update ProductionOrderHistory and ProductionOrderPriority
                    $allInfoOfLosx = InfoCongDoan::where('lo_sx', $infoCongDoan->lo_sx)->where('line_id', $infoCongDoan->line_id)->get();
                    $productionOrderHistory = ProductionOrderHistory::where('lo_sx', $infoCongDoan->lo_sx)->where('line_id', $infoCongDoan->line_id)->where('component_id', $infoCongDoan->product_id)->first();
                    $producedInfoQuantity = $allInfoOfLosx->sum('sl_dau_ra_hang_loat') - $allInfoOfLosx->sum('sl_ng');
                    if ($productionOrderHistory) {
                        $productionOrderHistory->update([
                            'produced_quantity' => $producedInfoQuantity,
                        ]);
                    }

                    $infos = InfoCongDoan::where('plan_id', $infoCongDoan->plan_id)->get();
                    $producedQuantity = $infos->sum('sl_dau_ra_hang_loat') - $infos->sum('sl_ng');
                    if ($producedQuantity >= $infoCongDoan->plan->sl_giao_sx) {
                        $infoCongDoan->plan->update(['status_plan' => ProductionPlan::STATUS_COMPLETED]);
                    }
                }
                if ($machine && $tracking) {
                    MachineStatus::deactive($machine->code);
                    $tracking->update([
                        'lot_id' => null,
                        'input' => 0,
                        'output' => 0
                    ]);
                }
                DB::commit();
                return $this->success($this->formatTemTrang($infoCongDoan, $request), "Kết thúc sản xuất thành công");
            } catch (\Throwable $th) {
                DB::rollBack();
                throw $th;
                return $this->failure($th, "Lỗi kết thúc sản xuất " . $th->getMessage());
            }
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    public function createGroupYellowStamp(Request $request)
    {
        $input = $request->all();
        $info = InfoCongDoan::find($input['info_cong_doan_id']);
        if (!$info) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        $check = GroupYellowStampInfo::where('info_cong_doan_id', $info->id);
        if (!$check) {
            return $this->failure($info, 'Lot đã được gộp tem vàng');
        }
        $group_yellow_stamp = GroupYellowStamp::where('lo_sx', $info->lo_sx)->where('line_id', $info->line_id)->where('machine_id', $info->machine_code)->first();
        if ($group_yellow_stamp) {
            $group_yellow_stamp->update([
                'quantity' => $group_yellow_stamp->quantity + $info->sl_tem_vang,
            ]);
            GroupYellowStampInfo::create(['info_cong_doan_id' => $info->id, 'group_yellow_stamp_id' => $group_yellow_stamp->id]);
        } else {
            $group_yellow_stamp = GroupYellowStamp::create([
                'lo_sx' => $info->lo_sx,
                'line_id' => $info->line_id,
                'machine_id' => $info->machine_code,
                'quantity' => $info->sl_tem_vang,
            ]);
            GroupYellowStampInfo::create(['info_cong_doan_id' => $info->id, 'group_yellow_stamp_id' => $group_yellow_stamp->id]);
        }
        return $this->success('', 'Đã gộp tem vàng');
    }

    public function getGroupYellowStamp(Request $request)
    {
        $input = $request->all();
        $info = InfoCongDoan::find($input['info_cong_doan_id']);
        if (!$info) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        $lots_tem_vang = [];
        $group_yellow_stamp = GroupYellowStamp::where('lo_sx', $input['lo_sx'])->where('line_id', $input['line_id'])->where('machine_id', $input['machine_id'])->first();
        $relate = GroupYellowStampInfo::where('info_cong_doan_id', $info->id)->where('group_yellow_stamp_id', $group_yellow_stamp->id ?? null)->first();
        if ($group_yellow_stamp) {
            if($relate){
                $group_yellow_stamp->is_grouped = true;
            }
            $lots_tem_vang = Lot::where('id', 'like', $group_yellow_stamp->lo_sx . ".$group_yellow_stamp->line_id." . "LTV.%")->orderBy('id')->get();
            $group_yellow_stamp->lots = $lots_tem_vang;
            $group_yellow_stamp->quantity = ($group_yellow_stamp->quantity - $lots_tem_vang->sum('so_luong'));
        }
        
        return $this->success($group_yellow_stamp);
    }

    public function printGroupYellowStamp(Request $request){
        $input = $request->all();
        $group_yellow_stamp = GroupYellowStamp::with(['info_cong_doan.qcHistory.yellowStampHistories', 'losx.product'])->find($input['id']);
        if (!$group_yellow_stamp) {
            return $this->failure('', 'Không tìm thấy lịch sử gom tem vàng');
        }
        if($group_yellow_stamp->quantity < $input['quantity']){
            return $this->failure('', 'Số lượng in vượt quá số lượng tồn, không thể tạo tem');
        }
        $prefix = $group_yellow_stamp->lo_sx . '.' . $group_yellow_stamp->line_id . '.LTV.';
        $lotList = Lot::where('id', 'like', "$prefix%")->orderBy('id', 'DESC')->get();
        if($lotList->sum('so_luong') >= $input['quantity']){
            return $this->failure('', 'Đã in hết số lượng gom tem vàng');
        }
        $latestInfo = $lotList->first();
        try {
            if($latestInfo){
                $array = explode('.', $latestInfo->id);
                $index = $latestInfo ? (int) end($array) : 0;
            }else{
                $index = 0;
            }
        } catch (\Throwable $th) {
            throw $th;
            $index = 0;
        }
        $newSequence = str_pad($index + 1, 4, '0', STR_PAD_LEFT);
        $new_lot_id = $prefix . $newSequence;
        try {
            DB::beginTransaction();
            $lot_tem_vang = Lot::updateOrCreate(
            ['id' => $new_lot_id],
            [
                'lo_sx' => $group_yellow_stamp->lo_sx,
                'final_line_id' => $group_yellow_stamp->line_id,
                'so_luong' => $request->quantity,
                'product_id' => $group_yellow_stamp->losx->product_id ?? null,
                'type' => Lot::TYPE_TEM_VANG
            ]);
            $tem = $this->formatGroupYellowStamp($lot_tem_vang, $group_yellow_stamp);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
        return $this->success($tem);
    }

    function formatGroupYellowStamp($lot_tem_vang, $group_yellow_stamp){
        $infos = $group_yellow_stamp->info_cong_doan;
        $khoanh_vung = [];
        foreach ($infos as $info) {
            foreach ($info->qcHistory->yellowStampHistories ?? [] as $value) {
                $khoanh_vung[] = $value->errors;
            }
        }
        // return $khoanh_vung;
        $tem = [
            'lot_id' => $lot_tem_vang->id,
            'lsx' => $lot_tem_vang->lo_sx,
            'machine_code' => $group_yellow_stamp->machine_id,
            'ten_sp' => $group_yellow_stamp->losx->product->name ?? null,
            'sl_tem_vang' => $lot_tem_vang->so_luong,
            'ver' => '',
            'his' => '',
            'cd_thuc_hien' => 'Đục cắt',
            'cd_tiep_theo' => 'Chọn',
            'ghi_chu' => implode(',', $khoanh_vung),
        ];
        return $tem;
    }

    public function reprintGroupYellowStamp(Request $request){
        $input = $request->all();
        $lot_tem_vang = Lot::find($input['lot_id']);
        if(!$lot_tem_vang){
            return $this->failure('', 'Không tìm thấy lot');
        }
        $group_yellow_stamp = GroupYellowStamp::where('lo_sx', $input['lo_sx'])->where('line_id', $input['line_id'])->where('machine_id', $input['machine_id'])->first();
        if(!$group_yellow_stamp){
            return $this->failure('', 'Không tìm thấy lịch sử gom tem vàng');
        }
        $tem = $this->formatGroupYellowStamp($lot_tem_vang, $group_yellow_stamp);
        return $this->success($tem);
    }

    //=============================End=============================



    //Quét NVL vào công đoạn gấp dán
    public function scanMaterial(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $isExist = InfoCongDoan::where('machine_code', $machine->code)->where('status', 1)->first();
        if ($isExist) {
            return $this->failure('', 'Có lot chưa hoàn thành, không thể tiếp tục lot khác');
        }
        if ($machine->is_iot) {
            $checksheet_logs = CheckSheetLog::where('info->machine_id', $machine->code)->whereDate('created_at', Carbon::today())->get();
            if (count($checksheet_logs) <= 0) {
                return $this->failure([], "Chưa nhập kiểm tra checksheet");
            }
        }
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if (!$tracking) {
            $tracking = Tracking::create([
                'machine_id' => $machine->code,
                'input' => 0,
                'output' => 0
            ]);
        }
        if ($tracking->lot_id) {
            return $this->failure([], "Máy này đang sản xuất");
        }
        $roll = RollMaterial::with(['material.products', 'warehouse_inventory'])->find($request->roll_id);
        // return $roll;
        // $material = Material::with('bom.product')->find($request->material_id);
        // if (!$roll) {
        //     return $this->failure([], "Không tìm thấy cuộn");
        // }
        // if (!$roll->warehouse_inventory || $roll->warehouse_inventory->quantity <= 0) {
        //     return $this->failure([], "Cuộn đã quét rồi");
        // }
        // if (!$roll->material) {
        //     return $this->failure([], "Không tìm thấy NVL: ". ($roll->material_id ?? ""));
        // }
        // $product_ids = $roll->material->products->pluck('id')->toArray() ?? [];
        // if (count($product_ids) === 0) {
        //     return $this->failure([], "Không tìm thấy sản phẩm");
        // }
        $lot_plan = LotPlan::where('lot_id', $request->lot_id)->where('line_id', $machine->line_id)->where('machine_code', $machine->code)->first();
        // if (!in_array($lot_plan->product_id, $product_ids)) {
        //     return $this->failure([], "Mã cuộn không phù hợp");
        // }
        // }
        if (empty($lot_plan) || $lot_plan->infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot cần chạy");
        }
        try {
            DB::beginTransaction();
            if ($lot_plan->plan && $lot_plan->plan->status_plan == ProductionPlan::STATUS_PENDING) {
                $lot_plan->plan->update(['status_plan' => ProductionPlan::STATUS_IN_PROGRESS]);
            }
            // $roll->warehouse_inventory->update(['quantity' => 0]);
            MachineStatus::reset($machine->code);
            InfoCongDoan::firstOrCreate(
                ['lot_id' => $lot_plan->lot_id, 'lot_plan_id' => $lot_plan->id, 'line_id' => $machine->line_id, 'machine_code' => $machine->code],
                [
                    'input_lot_id' => $request->roll_id,
                    'lo_sx' => $lot_plan->lo_sx,
                    'product_id' => $lot_plan->product_id,
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_INPROGRESS,
                    'user_id' => $request->user()->id,
                    'sl_kh' => $lot_plan->quantity,
                ]
            );
            $tracking->update([
                'lot_id' => $lot_plan->lot_id,
                'input' => 0,
                'output' => 0
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th->getMessage(), "Lỗi quét tem");
        }
        return $this->success([], "Bắt đầu sản xuất");
    }

    //Quét lot vào công đoạn
    public function scanManufacture(Request $request)
    {
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $isExist = InfoCongDoan::where('machine_code', $machine->code)->where('status', 1)->first();
        if ($isExist) {
            return $this->failure('', 'Có lot chưa hoàn thành, không thể tiếp tục lot khác');
        }
        if ($machine->is_iot) {
            $checksheet_logs = CheckSheetLog::where('info->machine_id', $machine->code)->whereDate('created_at', Carbon::today())->get();
            if (count($checksheet_logs) <= 0) {
                return $this->failure([], "Chưa nhập kiểm tra checksheet");
            }
            $tracking = Tracking::where('machine_id', $machine->code)->first();
            if ($machine->is_iot == 1 && !$tracking) {
                return $this->failure([], "Máy này chưa được sử dụng");
            }
            if ($tracking->lot_id && $tracking->lot_id !== $request->lot_id) {
                return $this->failure([], "Máy này đang sản xuất lot khác");
            }
        }
        $lot_plan = LotPlan::where('lot_id', $request->lot_id)->whereDate('start_time', date('Y-m-d'))->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->first();
        if (!$lot_plan) {
            return $this->failure([], 'Không tìm thấy lot');
        }
        $hanh_trinh_san_xuat = Spec::where('slug', 'hanh-trinh-san-xuat')->where('product_id', $lot_plan->product_id)->whereRaw('value REGEXP "^[0-9]+$"')->orderBy('value')->pluck('value', 'line_id');
        $requestValue = $hanh_trinh_san_xuat[$request->line_id] ?? 0;
        // Lọc các line_id có value nhỏ hơn requestValue
        $filteredLineIds = collect($hanh_trinh_san_xuat)
            ->filter(function ($value, $lineId) use ($requestValue) {
                return $value < $requestValue;
            })->keys();
        $orderByString = "'" . implode("','", $filteredLineIds->toArray()) . "'";
        $previousLineLot = InfoCongDoan::where('lot_id', $request->scanned_lot)
            ->whereIn('line_id', $filteredLineIds)->where('status', InfoCongDoan::STATUS_COMPLETED)
            ->orderByRaw("FIELD(line_id, $orderByString)")
            ->get()
            ->last();
        if (count($filteredLineIds) > 0) {
            if (!$previousLineLot) {
                return $this->failure([], 'Không tìm thấy lot đã chạy trước đó');
            }
            if ($previousLineLot->line_id == 24) {
                $bomProducts = Bom::where(function ($subQuery) use ($previousLineLot) {
                    $subQuery->where('material_id', $previousLineLot->product_id)->orWhere('product_id', $previousLineLot->product_id);
                })->pluck('product_id')->toArray();
                if (!in_array($lot_plan->product_id, $bomProducts)) {
                    return $this->failure($previousLineLot, 'Không khớp mã sản phẩm');
                }
            } else {
                if ($previousLineLot->product_id !== $lot_plan->product_id) {
                    return $this->failure([$previousLineLot, $lot_plan], 'Không khớp mã sản phẩm');
                }
            }
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $machine->line_id)->first();
        if ($infoCongDoan) {
            return $this->failure([], "Đã quét lot này");
        }
        try {
            DB::beginTransaction();
            MachineStatus::reset($machine->code);
            if ($lot_plan->plan && $lot_plan->plan->status_plan == ProductionPlan::STATUS_PENDING) {
                $lot_plan->plan->update(['status_plan' => ProductionPlan::STATUS_IN_PROGRESS]);
            }
            $infoCongDoan = InfoCongDoan::firstOrCreate(
                ['lot_id' => $lot_plan->lot_id, 'lot_plan_id' => $lot_plan->id, 'line_id' => $machine->line_id, 'machine_code' => $machine->code],
                [
                    'input_lot_id' => $request->scanned_lot,
                    'lo_sx' => $lot_plan->lo_sx,
                    'product_id' => $lot_plan->product_id,
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_INPROGRESS,
                    'user_id' => $request->user()->id,
                    'sl_kh' => $lot_plan->quantity,
                ]
            );
            $lot = Lot::where('id', $request->scanned_lot)->first();
            if ($lot) {
                $line_inventory = LineInventories::where('product_id', $lot->product_id)->where('line_id', $lot->final_line_id)->first();
                $sl_dat = $lot->so_luong;
                if ($line_inventory) {
                    $line_inventory->update(['quantity' => $line_inventory->quantity - $sl_dat]);
                } else {
                    LineInventories::create(['quantity' => $sl_dat, 'line_id' => $infoCongDoan->line_id, 'product_id' => $infoCongDoan->product_id]);
                }
            }
            if (isset($tracking)) {
                $tracking->update([
                    'lot_id' => $request->lot_id,
                    'input' => 0,
                    'output' => 0
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
            return $this->failure($th, "Lỗi quét lot");
        }
        // } else {
        //     return $this->failure([], "Không tìm thấy lot phù hợp");
        // }
        return $this->success([], "Quét lot thành công");
    }

    public function getLotErrorLogList(Request $request)
    {
        $lotErrorLog = LotErrorLog::where('lot_id', $request->lot_id)->where('line_id', $request->line_id)->where('machine_code', $request->machine_code)->get();
        $errorList = [];
        $log = [];
        $stt = 0;
        foreach ($lotErrorLog as $item) {
            $stt++;
            $errorLog = [];
            $index = 0;
            $quantity = 0;
            foreach ($item->log ?? [] as $key => $value) {
                $errorLog[] = [
                    'key' => $index,
                    'error_id' => $key,
                    'quantity' => $value,
                ];
                $quantity += $value;
                $index++;
            }
            $errorList[] = [
                'key' => $stt,
                'stt' => $stt,
                'quantity' => $quantity,
                'date' => Carbon::parse($item->created_at)->format('d/m/Y H:i:s'),
                'user_name' => CustomUser::find($item->user_id)->name ?? "",
                'log' => $errorLog
            ];
        }
        return $this->success(['errorList' => $errorList, 'log' => $log]);
    }

    //Truy vấn dữ liệu lỗi công đoạn
    public function findError(Request $request)
    {
        $error = Error::where('id', $request->error_id)->first();
        if ($error) {
            return $this->success($error);
        } else {
            return $this->failure([], "Không tìm thấy mã lỗi");
        }
    }

    //Cập nhật danh sách lỗi cho lot
    public function updateLotErrorLog(Request $request)
    {
        if ($request->line_id === '29') {
            return $this->updateLotErrorLogForSelectionLine($request);
        } else {
            return $this->updateLotErrorLogForNormalLine($request);
        }
    }

    public function updateLotErrorLogForNormalLine($request)
    {
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if ($machine->is_iot == 1 && !$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->first();
        if ($infoCongDoan) {
            try {
                if (empty($request->log)) {
                    return $this->failure([], "Chưa nhập dấu nối");
                }
                DB::beginTransaction();
                $log = LotErrorLog::create([
                    'lot_id' => $infoCongDoan->lot_id,
                    'log' => $request->log,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'machine_code' => $infoCongDoan->machine_code,
                    'line_id' => $infoCongDoan->line_id,
                    'user_id' => $request->user()->id
                ]);
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi cập nhật");
            }
            return $this->success([], "Cập nhật lỗi thành công");
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    public function updateLotErrorLogForSelectionLine($request)
    {
        $line = Machine::find($request->line);
        if (!$line) {
            return $this->failure([], "Không tìm công đoạn");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($infoCongDoan) {
            try {
                DB::beginTransaction();
                $log = LotErrorLog::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
                if ($log) {
                    $log->update([
                        'log' => $request->log
                    ]);
                } else {
                    $log = LotErrorLog::create([
                        'lot_id' => $infoCongDoan->lot_id,
                        'log' => $request->log,
                        'lo_sx' => $infoCongDoan->lo_sx,
                        'line_id' => $infoCongDoan->line_id,
                        'user_id' => $request->user()->id
                    ]);
                }
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi cập nhật");
            }
            return $this->success([], "Cập nhật lỗi thành công");
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }
    public function formatTimestampWithTimezone($timestamp)
    {
        $timestampInSeconds = $timestamp / 1000;
        $formattedDate = Carbon::createFromTimestamp($timestampInSeconds, 'Asia/Bangkok')
            ->format('Y-m-d H:i:s');
        return $formattedDate;
    }


    //Kết thúc sản xuất lot
    public function endOfProduction(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        // if ($line->id == 29) {
        //     $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        // } else {
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if ($machine->is_iot == 1 && !$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        // }

        if ($infoCongDoan) {
            if (!$infoCongDoan->qcHistory) {
                return $this->failure([], 'Chưa kiểm tra QC');
            }
            if (!$this->checkEligibleForPrinting($infoCongDoan)) {
                return $this->failure([], "Chưa kiểm tra đủ tiêu chí QC");
            }
            try {
                DB::beginTransaction();
                $counter = $this->fetchDataFromApi($machine->device_id);
                if ($machine->is_iot == 1 && $counter['PLC:Num_Out'][0]['value'] && $counter['PLC:Num_Out'][0]['value'] - $tracking->output > 0) {
                    $sl_dau_ra_hang_loat = ($counter['PLC:Num_Out'][0]['value'] - $tracking->output) * ($infoCongDoan->product->so_bat ?? 1);
                } else {
                    $sl_dau_ra_hang_loat = $infoCongDoan->sl_dau_ra_hang_loat;
                }
                if ($machine->is_iot == 1 && $counter['PLC:Num_Out'][0]['ts'] && $machine->is_iot == 1) {
                    $thoi_gian_ket_thuc = $this->formatTimestampWithTimezone($counter['PLC:Num_Out'][0]['ts']);
                } else {
                    $thoi_gian_ket_thuc = Carbon::now();
                }
                if ($machine->is_iot == 1) {
                    $infoCongDoan->update([
                        'thoi_gian_ket_thuc' => $thoi_gian_ket_thuc,
                        'sl_dau_ra_hang_loat' => $sl_dau_ra_hang_loat,
                        'sl_dau_ra_ket_thuc' => $counter['PLC:Num_Out'][0]['value'] ?? 0,
                        'sl_dau_vao_ket_thuc' => $counter['PLC:Num_Input'][0]['value'] ?? 0,
                        'status' => InfoCongDoan::STATUS_COMPLETED
                    ]);
                } else {
                    $infoCongDoan->update([
                        'thoi_gian_ket_thuc' => $thoi_gian_ket_thuc,
                        'sl_dau_ra_hang_loat' => $sl_dau_ra_hang_loat,
                        'status' => InfoCongDoan::STATUS_COMPLETED
                    ]);
                }
                $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
                if ($sl_con_lai < 0) {
                    return $this->failure([], "Số lượng sản xuất không hợp lệ");
                }
                $lot = Lot::find($infoCongDoan->lot_id);
                if (!$lot) {
                    Lot::create([
                        'id' => $infoCongDoan->lot_id,
                        'product_id' => $infoCongDoan->product_id,
                        'material_id' => $infoCongDoan->material_id,
                        'lo_sx' => $infoCongDoan->lo_sx,
                        'final_line_id' => $line->id,
                        'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang,
                        'type' => Lot::TYPE_TEM_TRANG
                    ]);
                } else {
                    $lot->update([
                        'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang,
                        'final_line_id' => $line->id,
                    ]);
                }
                $line_inventory = LineInventories::where('product_id', $infoCongDoan->product_id)->where('line_id', $infoCongDoan->line_id)->first();
                $sl_dat = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng;
                if ($line_inventory) {
                    $line_inventory->update(['quantity' => $line_inventory->quantity + $sl_dat]);
                } else {
                    LineInventories::create(['quantity' => $sl_dat, 'line_id' => $infoCongDoan->line_id, 'product_id' => $infoCongDoan->product_id]);
                }
                $spec = Spec::where('product_id', $infoCongDoan->product_id)->where('line_id', $infoCongDoan->line_id)->where('slug', 'so-luong')->first();
                $count_info = InfoCongDoan::where('input_lot_id', $infoCongDoan->input_lot_id)->where('machine_code', $infoCongDoan->machine_code)->count();
                if ($spec && $line->id == 24 && $infoCongDoan->sl_dau_ra_hang_loat >= $spec->value && $count_info == 1 && $machine->is_iot == 1) {
                    $lotCurrent = LotPlan::where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->where('lot_id', $infoCongDoan->lot_id)->first();
                    $lotNext = LotPlan::where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->where('id', '>', $lotCurrent->id)->orderBy('id', 'ASC')->first();
                    if ($lotNext) {
                        $tracking = Tracking::where('machine_id', $infoCongDoan->machine_code)->first();
                        $tracking->input = $counter['PLC:Num_Input'][0]['value'] ?? 0;
                        $tracking->output = $counter['PLC:Num_Out'][0]['value'] ?? 0;
                        $tracking->lot_id = $lotNext->lot_id;
                        $tracking->save();
                        InfoCongDoan::create([
                            'input_lot_id' => $infoCongDoan->input_lot_id,
                            'lot_plan_id' => $lotNext->id,
                            'lot_id' => $lotNext->lot_id,
                            'lo_sx' => $lotNext->lo_sx,
                            'line_id' => $lotNext->line_id,
                            'machine_code' => $lotNext->machine_code,
                            'product_id' => $lotNext->product_id,
                            'sl_kh' => $lotNext->quantity,
                            'sl_dau_vao_hang_loat' => 0,
                            'sl_khi_bam_may' => $counter['PLC:Num_Out'][0]['value'] ?? 0,
                            'sl_dau_vao_bam_may' => $counter['PLC:Num_Input'][0]['value'] ?? 0,
                            'thoi_gian_bat_dau' => $thoi_gian_ket_thuc,
                            'thoi_gian_bam_may' => $thoi_gian_ket_thuc,
                            'user_id' => $request->user()->id,
                            'status' => InfoCongDoan::STATUS_INPROGRESS
                        ]);
                    } else {
                        if (isset($machine) && isset($tracking)) {
                            MachineStatus::deactive($machine->code);
                            $tracking->update([
                                'lot_id' => null,
                                'input' => 0,
                                'output' => 0
                            ]);
                        }
                    }
                } else {
                    if (isset($machine) && isset($tracking)) {
                        MachineStatus::deactive($machine->code);
                        $tracking->update([
                            'lot_id' => null,
                            'input' => 0,
                            'output' => 0
                        ]);
                    }
                }

                $check = LotPlan::where('lo_sx', $infoCongDoan->lo_sx)->whereNotIn('lot_id', function ($query) {
                    $query->select(DB::raw("lot_id COLLATE utf8mb4_unicode_ci"))
                        ->from('info_cong_doan');
                })->count();
                // if ($check === 0) {
                //     ProductionPlan::where('lo_sx', $infoCongDoan->lo_sx)->update(['status_plan' => ProductionPlan::STATUS_COMPLETED]);
                // }
                DB::commit();
                return $this->success($this->formatTemTrang($infoCongDoan, $request), "Kết thúc sản xuất thành công");
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi kết thúc sản xuất" . $th->getMessage());
            }
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    /**
     * In lại tem với status = 2
     * @param Request $request
     */
    public function reprintTem(Request $request)
    {
        $request->validate([
            'list' => 'required|array',
            // 'list.*.info_id' => 'required',
            'list.*.lot_id' => 'required',
        ]);

        $result = [];
        foreach ($request->list as $record) {
            $record = (object) $record;
            $info = InfoCongDoan::where('lot_id', $record->lot_id)->first();
            if (!empty($info)) {
                $param = (object) ['lot_id' => $record->lot_id];
                $result[] = $this->formatTemTrang($info, $param);
                Lot::updateOrCreate(['id' => $info->lot_id], [
                    'id' => $info->lot_id,
                    'product_id' => $info->product_id,
                    'lo_sx' => $info->lo_sx,
                    'so_luong' => $info->sl_dau_ra_hang_loat - $info->sl_ng - $info->sl_tem_vang,
                    'final_line_id' => $info->line_id,
                    'type' => Lot::TYPE_TEM_TRANG,
                ]);
            }
        }
        return $this->success($result);
    }

    public function formatTemTrang($infoCongDoan, $request)
    {
        $product = $infoCongDoan->product;
        $material = $infoCongDoan->material;
        $line = $infoCongDoan->line;
        if ($line->id === 24) {
            $losx = $infoCongDoan->losx;
            if ($losx && $losx->product_id) {
                $product_id = $losx->product_id;
            } else {
                $bom = Bom::where('material_id', $infoCongDoan->product_id)->where('priority', 1)->first();
                if ($bom && $bom->product_id) {
                    $product_id = $bom->product_id;
                } else {
                    $product_id = $infoCongDoan->product_id;
                }
            }
        } else {
            $product_id = $infoCongDoan->product_id;
        }
        $product_journey = Spec::where('product_id', $product_id)->where('slug', 'hanh-trinh-san-xuat')->whereRaw('value REGEXP "^[0-9]+$"')->orderBy('value')->pluck('value', 'line_id');
        $currnetLineIndex = $product_journey[$infoCongDoan->line_id] ?? 0;
        $nextLineIds = collect($product_journey)
            ->filter(function ($value, $lineId) use ($currnetLineIndex) {
                return $value > $currnetLineIndex;
            })->keys();
        if (count($nextLineIds) > 0) {
            $next_line = Line::find($nextLineIds[0]);
        } else {
            $next_line = Line::where('ordering', '>', $line->ordering)->orderBy('ordering')->first();
        }
        $user = CustomUser::find($infoCongDoan->user_id ?? "");
        $lotErrorLog = LotErrorLog::where('lot_id', $request->lot_id)->orderBy('line_id')->get();
        $log = [];
        foreach ($lotErrorLog as $item) {
            foreach ($item->log ?? [] as $key => $value) {
                $log[$key] = ($log[$key] ?? 0) + $value;
            }
        }
        $errors = [];
        foreach ($log as $key => $value) {
            $errors[] = "$key: $value";
        }
        $ghi_chu = implode(', ', $errors);
        $data = [];
        $data['lot_id'] = $infoCongDoan->lot_id;
        $data['lsx'] = $infoCongDoan->lo_sx;
        $data['ten_sp'] = $product->name ?? $material->name ?? "";
        $data['soluongtp'] = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
        $data['his'] = $product->his ?? "";
        $data['ver'] = $product->ver ?? "";
        $data['cd_thuc_hien'] = $line->name ?? "";
        $data['cd_tiep_theo'] = $next_line->name ?? "";
        // $data['nguoi_sx'] = $user->name ?? "";
        $data['ghi_chu'] = $ghi_chu ?? "";
        $data['machine_code'] = $infoCongDoan->machine_code;
        return $data;
    }

    //San lot khi vào công đoạn chọn
    public function scanForSelectionLine(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        // $lot = Lot::find($request->scanned_lot);
        // if (!$lot) {
        //     return $this->failure([], "Lot này chưa được sản xuất");
        // }
        $check = InfoCongDoan::whereDate('created_at', date('Y-m-d'))->where('machine_code', $machine->code)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($check) {
            return $this->failure([], "Chưa hoàn thành lot trước đó");
        }
        $lot_plan = LotPlan::where('lot_id', $request->lot_id)->whereDate('start_time', date('Y-m-d'))->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->first();
        if (!$lot_plan) {
            return $this->failure([], 'Không tìm thấy lot');
        }
        // $hanh_trinh_san_xuat = Spec::where('slug', 'hanh-trinh-san-xuat')->where('product_id', $lot_plan->product_id)->whereRaw('value REGEXP "^[0-9]+$"')->orderBy('value')->pluck('value', 'line_id');
        // $requestValue = $hanh_trinh_san_xuat[$request->line_id] ?? 0;
        // // Lọc các line_id có value nhỏ hơn requestValue
        // $filteredLineIds = collect($hanh_trinh_san_xuat)
        //     ->filter(function ($value, $lineId) use ($requestValue) {
        //         return $value < $requestValue;
        //     })->keys();
        // $orderByString = "'" . implode("','", $filteredLineIds->toArray()) . "'";
        // $previousLineLot = InfoCongDoan::where('lot_id', $request->scanned_lot)
        //     ->whereIn('line_id', $filteredLineIds)->where('status', InfoCongDoan::STATUS_COMPLETED)
        //     ->orderByRaw("FIELD(line_id, $orderByString)")
        //     ->get()
        //     ->last();
        // if (count($filteredLineIds) > 0) {
        //     if (!$previousLineLot) {
        //         return $this->failure([], 'Không tìm thấy lot đã chạy trước đó');
        //     }
        //     if ($previousLineLot->line_id == 24) {
        //         $bomProducts = Bom::where(function ($subQuery) use ($previousLineLot) {
        //             $subQuery->where('material_id', $previousLineLot->product_id)->orWhere('product_id', $previousLineLot->product_id);
        //         })->pluck('product_id')->toArray();
        //         if (!in_array($lot_plan->product_id, $bomProducts)) {
        //             return $this->failure($previousLineLot, 'Không khớp mã sản phẩm');
        //         }
        //     } else {
        //         if ($previousLineLot->product_id !== $lot_plan->product_id) {
        //             return $this->failure([$previousLineLot,$lot_plan], 'Không khớp mã sản phẩm');
        //         }
        //     }
        // }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $line->id)->where('status', 1)->first();
        if ($infoCongDoan) {
            return $this->failure([], "Đã quét lot này");
        }
        try {
            DB::beginTransaction();
            InfoCongDoan::firstOrCreate(
                ['lot_id' => $lot_plan->lot_id, 'lot_plan_id' => $lot_plan->id, 'line_id' => $machine->line_id, 'machine_code' => $machine->code],
                [
                    'input_lot_id' => $request->scanned_lot,
                    'lot_id' => $lot_plan->lot_id,
                    'lo_sx' => $lot_plan->lo_sx,
                    'line_id' => $line->id,
                    'product_id' => $lot_plan->product_id,
                    'sl_kh' => $lot_plan->quantity,
                    'sl_dau_vao_hang_loat' => $lot_plan->quantity,
                    'sl_dau_ra_hang_loat' => $lot_plan->quantity,
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'user_id' => $request->user()->id,
                    'status' => InfoCongDoan::STATUS_INPROGRESS,
                    'machine_code' => $machine->code,
                    'lot_plan_id' => $lot_plan->id
                ]
            );
            $lot = Lot::where('id', $request->scanned_lot)->first();
            if ($lot) {
                $line_inventory = LineInventories::where('product_id', $lot->product_id)->where('line_id', $lot->final_line_id)->first();
                $sl_dat = $lot->so_luong;
                if ($line_inventory) {
                    $line_inventory->update(['quantity' => $line_inventory->quantity - $sl_dat]);
                } else {
                    LineInventories::create(['quantity' => $sl_dat]);
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi quét lot");
        }
        return $this->success([], "Bắt đầu sản xuất");
    }

    //Lấy dữ liệu giao việc
    public function getAssignment(Request $request)
    {
        $info = InfoCongDoan::where('lot_id', $request->lot_id)->first();
        $assignment = Assignment::with(['worker:id,name', 'lot'])->where('lot_id', $request->lot_id)->get();
        foreach ($assignment as $item) {
            $item['so_luong'] = $info->sl_dau_vao_hang_loat ?? 0;
        }
        return $this->success($assignment);
    }

    public function getInfoPrintSelection(Request $request)
    {
        $oll_bin = OddBin::where('lo_sx', $request->lo_sx)->sum('so_luong');
        $assignment = Assignment::where('lot_id', $request->lot_id)->sum('ok_quantity');
        $data = [
            'sl_ton' => $oll_bin,
            'sl_ok' => $assignment,
            'sl_tong' => $oll_bin + $assignment
        ];
        return $this->success($data);
    }
    //Tạo dữ liệu cho bảng Assignment
    public function createAssignment(Request $request)
    {
        $info = InfoCongDoan::where('lot_id', $request->lot_id)->first();
        if (!$info) {
            return $this->failure([], "Không tìm thấy lot");
        }
        if (empty($request->worker_id)) {
            return $this->failure([], "Không có người phụ trách");
        }
        try {
            DB::beginTransaction();
            $assignment = Assignment::updateOrCreate(
                ['lot_id' => $request->lot_id],
                [
                    'lot_id' => $request->lot_id,
                    'assigned_quantity' => $request->assigned_quantity,
                    'actual_quantity' => $request->actual_quantity,
                    'ok_quantity' => $request->actual_quantity,
                    'worker_id' => $request->worker_id
                ]
            );
            DB::commit();
        } catch (\Throwable $th) {
            //throw $th;
            DB::rollBack();
            return $this->failure($th, "Lỗi tạo giao việc");
        }

        return $this->success($assignment);
    }

    //Xoá dữ liệu cho bảng Assignment
    public function deleteAssignment(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $assignment = Assignment::find($id);
            if ($assignment->actual_quantity > 0) {
                return $this->failure([], "Không thể xoá giao việc đã thực hiện");
            }
            $assignment->delete();
            DB::commit();
        } catch (\Throwable $th) {
            //throw $th;
            DB::rollBack();
            return $this->failure($th, "Lỗi xoá giao việc");
        }

        return $this->success($assignment);
    }

    //Cập nhật dữ liệu cho bảng Assignment
    public function updateAssignment(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $assignment = Assignment::find($id);
            if (!$assignment) {
                return $this->failure([], "Lot này chưa được giao việc");
            }
            $assignment->update([
                'actual_quantity' => $request->actual_quantity ?? 0,
                'ok_quantity' => $request->ok_quantity ?? 0,
            ]);
            $infoCongDoan = InfoCongDoan::where('lot_id', $assignment->lot_id)->where('line_id', 29)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
            if ($infoCongDoan) {
                $infoCongDoan->update([
                    'sl_dau_ra_hang_loat' => $request->actual_quantity ?? 0,
                ]);
                $lot = Lot::updateOrCreate(
                    ['id' => $infoCongDoan->lot_id, 'product_id' => $infoCongDoan->product_id, 'lo_sx' => $infoCongDoan->lo_sx],
                    [
                        'so_luong' => $request->ok_quantity ?? 0,
                        'final_line_id' => 29
                    ]
                );
            }
            DB::commit();
        } catch (\Throwable $th) {
            throw $th;
            DB::rollBack();
            return $this->failure($th, "Lỗi cập nhật giao việc");
        }

        return $this->success($assignment);
    }

    public function fetchDataFromApi($deviceID)
    {
        // API endpoints
        $loginUrl = 'http://103.77.215.18:3030/api/auth/login';
        $dataUrl = 'http://103.77.215.18:3030/api/plugins/telemetry/DEVICE/' . $deviceID . '/values/timeseries?keys=PLC:Num_Out,PLC:Num_Input';

        // API login credentials
        $credentials = [
            'username' => 'messystem@gmail.com',
            'password' => 'mesors@2023',
        ];

        try {
            // Step 1: Get the token
            $client = new Client();
            $loginResponse = $client->post($loginUrl, [
                'json' => $credentials,
            ]);

            // Kiểm tra phản hồi và lấy token
            $loginData = json_decode($loginResponse->getBody(), true);

            if (isset($loginData['token'])) {
                $token = $loginData['token'];
            } else {
                throw new \Exception('Token not found in response');
            }

            // Step 2: Use the token to get data from the second API
            $dataResponse = $client->get($dataUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            // Parse the data from the response
            $data = json_decode($dataResponse->getBody(), true);

            return $data;
        } catch (\Exception $e) {
            // Handle exceptions
            return 'Error: ' . $e->getMessage();
        }
    }
    //In tem tại công đoạn Chọn
    public function printTemSelectionLine(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $info = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        if (!$info) {
            return $this->failure([], "Lot này chưa được sản xuất");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        if (!$infoCongDoan) {
            return $this->failure([], "Chưa quét lot này");
        }
        if (!$request->sl_in_tem) {
            return $this->failure([], "Số lượng in tem không hợp lệ");
        }
        if (!$this->checkEligibleForPrinting($infoCongDoan)) {
            return $this->failure([], "Chưa kiểm tra đủ tiêu chí QC");
        }
        $sl_ok = Assignment::where('lot_id', $request->lot_id)->sum('ok_quantity');
        $sl_ton = OddBin::where('lo_sx', $infoCongDoan->lo_sx)->sum('so_luong');
        $sl_tong = $sl_ok + $sl_ton;
        $data = [];
        try {
            DB::beginTransaction();
            $counter = floor($sl_tong / $request->sl_in_tem);
            if ($counter < 0) {
                return $this->failure([], "Số lượng in tem không hợp lệ");
            }
            $infoCongDoan->update([
                'thoi_gian_ket_thuc' => Carbon::now(),
                'status' => InfoCongDoan::STATUS_COMPLETED
            ]);
            if ($infoCongDoan->plan) {
                //Update ProductionOrderHistory and ProductionOrderPriority
                $allInfoOfLosx = InfoCongDoan::where('lo_sx', $infoCongDoan->lo_sx)->where('line_id', $infoCongDoan->line_id)->get();
                $productionOrderHistory = ProductionOrderHistory::where('lo_sx', $infoCongDoan->lo_sx)->where('line_id', $infoCongDoan->line_id)->where('component_id', $infoCongDoan->product_id)->first();
                $producedInfoQuantity = $allInfoOfLosx->sum('sl_dau_ra_hang_loat') - $allInfoOfLosx->sum('sl_ng');
                if ($productionOrderHistory) {
                    $productionOrderHistory->update([
                        'produced_quantity' => $producedInfoQuantity,
                    ]);
                }

                $infos = InfoCongDoan::where('plan_id', $infoCongDoan->plan_id)->get();
                $producedQuantity = $infos->sum('sl_dau_ra_hang_loat') - $infos->sum('sl_ng');
                if ($producedQuantity >= $infoCongDoan->plan->sl_giao_sx) {
                    $infoCongDoan->plan->update(['status_plan' => ProductionPlan::STATUS_COMPLETED]);
                }
            }
            $quantity = 0;
            $counterT = Lot::where('id', 'like', $infoCongDoan->lo_sx . '-T%')->count() + 1;
            switch ($request->type) {
                case 1:
                    $counter = ceil($sl_tong / $request->sl_in_tem);
                    for ($i = 0; $i < $counter; $i++) {
                        $id = $infoCongDoan->lo_sx . '-T';
                        if ($i == $counter - 1 && ($sl_tong % $request->sl_in_tem) > 0) {
                            $so_luong = $sl_tong % $request->sl_in_tem;
                        } else {
                            $so_luong = $request->sl_in_tem;
                        }
                        $thung = Lot::firstOrCreate([
                            'id' => $id . ($i + $counterT),
                            'product_id' => $infoCongDoan->product_id,
                            'material_id' => $infoCongDoan->material_id,
                            'final_line_id' => $line->id,
                            'lo_sx' => $infoCongDoan->lo_sx,
                            'so_luong' => $so_luong,
                            'type' => Lot::TYPE_THUNG
                        ]);
                        $quantity += $request->sl_in_tem;
                        $data[] = $this->formatTemChon($thung, $infoCongDoan);
                    }
                    OddBin::where('lo_sx', $infoCongDoan->lo_sx)->where('product_id', $infoCongDoan->product_id)->delete();
                    break;
                case 2:
                    $counter = floor($sl_tong / $request->sl_in_tem);
                    for ($i = 0; $i < $counter; $i++) {
                        $id = $infoCongDoan->id . '-T';
                        $thung = Lot::firstOrCreate([
                            'id' => $id . ($i + $counterT),
                            'product_id' => $infoCongDoan->product_id,
                            'material_id' => $infoCongDoan->material_id,
                            'final_line_id' => $line->id,
                            'lo_sx' => $infoCongDoan->lo_sx,
                            'so_luong' => $request->sl_in_tem,
                            'type' => Lot::TYPE_THUNG
                        ]);
                        $quantity += $request->sl_in_tem;
                        $data[] = $this->formatTemChon($thung, $infoCongDoan);
                    }
                    OddBin::updateOrCreate(
                        [
                            'lo_sx' => $infoCongDoan->lo_sx,
                            'product_id' => $infoCongDoan->product_id,
                        ],
                        [
                            'so_luong' => $sl_tong - $quantity,
                        ]
                    );
                    break;
                case 3:
                    OddBin::updateOrCreate(
                        [
                            'lo_sx' => $infoCongDoan->lo_sx,
                            'product_id' => $infoCongDoan->product_id,
                        ],
                        [
                            'so_luong' => $sl_tong,
                        ]
                    );
                    break;
            }
            DB::commit();
        } catch (\Throwable $th) {
            Log::info($th);
            DB::rollBack();
            return $this->failure($th, "Lỗi quét lot");
        }
        return $this->success($data);
    }

    public function formatTemChon($lot, $infoCongDoan)
    {
        $product = $lot->product;
        $material = $lot->material;
        $line = $infoCongDoan->line;
        $next_line = Line::where('ordering', '>', $line->ordering)->orderBy('ordering')->first();
        $user = CustomUser::find($infoCongDoan->user_id ?? "");
        $lotErrorLog = LotErrorLog::where('lot_id', $infoCongDoan->lot_id)->orderBy('line_id')->get();
        $log = [];
        foreach ($lotErrorLog as $item) {
            foreach ($item->log ?? [] as $key => $value) {
                $log[$key] = ($log[$key] ?? 0) + $value;
            }
        }
        $errors = [];
        foreach ($log as $key => $value) {
            $errors[] = "$key: $value";
        }
        $ghi_chu = implode(', ', $errors);
        $data = [];
        $assignment = Assignment::where('lot_id', $infoCongDoan->lot_id)->first();
        $data['lot_id'] = $lot->id;
        $data['lsx'] = $lot->lo_sx;
        $data['ten_sp'] = $product->name ?? $material->name ?? "";
        $data['soluongtp'] = $lot->so_luong;
        $data['his'] = $product->his ?? "";
        $data['ver'] = $product->ver ?? "";
        $data['cd_thuc_hien'] = $line->name ?? "";
        $data['cd_tiep_theo'] = $next_line->name ?? "";
        $data['nguoi_sx'] = $assignment->worker ? $assignment->worker->name : "";
        $data['ghi_chu'] = $ghi_chu ?? "";
        return $data;
    }

    public function updateOutputProduction(Request $request)
    {
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $request->machine_code)->where('line_id', $request->line_id)->first();
        if (!$infoCongDoan) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        if ($request->line_id == 29) {
            $infoCongDoan->update([
                'sl_dau_vao_hang_loat' => $request->input ?? 0,
                'sl_dau_ra_hang_loat' => $request->output ?? 0,
            ]);
            return $this->success('', 'Đã cập nhật sản lượng sản xuất');
        }
        if (!$infoCongDoan->thoi_gian_bam_may) {
            $infoCongDoan->update([
                'thoi_gian_bam_may' => date('Y-m-d H:i:s'),
                'sl_dau_vao_chay_thu' => $request->input ?? 0,
                'sl_dau_ra_chay_thu' => $request->output ?? 0,
            ]);
            return $this->success('', 'Đã cập nhật sản lượng vào hàng');
        } else {
            if (!isset($request->input)) {
                return $this->failure('', 'Chưa nhập sản lượng đầu vào');
            }
            if (!isset($request->output)) {
                return $this->failure('', 'Chưa nhập sản lượng đầu ra');
            }
            $infoCongDoan->update([
                'sl_dau_vao_hang_loat' => $request->input ?? 0,
                'sl_dau_ra_hang_loat' => $request->output ?? 0,
            ]);
            return $this->success('', 'Đã cập nhật sản lượng sản xuất');
        }
    }

    public function changeStatusNGTracking(Request $request)
    {
        $info = InfoCongDoan::find($request->info_cong_doan_id);
        if (!$info) {
            return $this->failure('', 'Không tìm thấy lot đang chạy');
        }
        $tracking = Tracking::where('machine_id', $info->machine_code)->where('lot_id', $info->lot_id)->first();
        if (!$tracking) {
            return $this->failure('', 'Không ghi nhận IOT tại máy này');
        }
        $ng_tracking = NGTracking::where('info_cong_doan_id', $info->id)->orderBy('created_at', 'DESC')->first();
        if (!$ng_tracking) {
            return $this->failure('', 'Chưa thể ghi nhận sản lượng NG');
        }
        if ($ng_tracking->status === NGTracking::COMPLETE_STATUS) {
            $ng_tracking = NGTracking::create([
                'status' => 0,
                'user_id' => $request->user()->id,
                'info_cong_doan_id' => $info->id,
            ]);
        }
        $status = $ng_tracking->status;
        $message = '';
        //Kiểm tra trạng thái được yêu cầu cho ng_tracking
        if (isset($request->status)) {
            $status = $request->status;
            if ($status === NGTracking::TRACKING_STATUS) {
                if ($ng_tracking->status === 0) {
                    $message = 'Bắt đầu ghi nhận sản lượng NG';
                } else {
                    $message = 'Tiếp tục ghi nhận sản lượng NG';
                }
            } else if ($status === NGTracking::STOPPED_STATUS || $status === NGTracking::PAUSING_STATUS) {
                $message = 'Đã dừng ghi nhận sản lượng NG';
            } else if ($status === NGTracking::COMPLETE_STATUS) {
                $message = 'Đã lưu sản lượng NG';
            }
        }
        $ng_tracking->update([
            'user_id' => $request->user()->id,
            'status' => $status
        ]);
        return $this->success($ng_tracking, $message);
    }

    public function checkNGTracking(Request $request)
    {
        $ng_tracking = NGTracking::where('info_cong_doan_id', $request->info_cong_doan_id)->orderBy('created_at', 'DESC')->first();
        if (!$ng_tracking) {
            //Chưa ghi nhận ng
            return $this->success('');
        } else {
            if ($ng_tracking->ng_quantity > 0) {
                //Đã hoàn tất ghi nhận NG trước đó, có thể cho phép ghi nhận ng lần nữa
                return $this->success($ng_tracking);
            } else {
                //Chưa hoàn tất ghi nhận NG, trả về tín hiệu cho phép kết thúc
                return $this->success($ng_tracking);
            }
        }
    }

    public function getNGTrackingResultList(Request $request)
    {
        $infoCongDoan = InfoCongDoan::find($request->info_cong_doan_id);
        if (!$infoCongDoan) {
            return $this->success(['errorList' => []]);
        }
        $errorList = [];
        $stt = 0;

        //Dấu nối
        if (!isset($request->type) || ($request->type === 'dau_noi')) {
            $lotErrorLog = LotErrorLog::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->get();
            foreach ($lotErrorLog as $item) {
                $stt++;
                $errorLog = [];
                $index = 0;
                $quantity = 0;
                foreach ($item->log ?? [] as $key => $value) {
                    $errorLog[] = [
                        'key' => $index,
                        'id' => $item->id ?? null,
                        'error_id' => $key,
                        'quantity' => $value,
                    ];
                    $quantity += $value;
                    $index++;
                }
                $errorList[] = [
                    'key' => $stt,
                    'stt' => $stt,
                    'type' => 'Dấu nối',
                    'quantity' => $quantity,
                    'date' => Carbon::parse($item->created_at)->format('d/m/Y H:i:s'),
                    'user_name' => CustomUser::find($item->user_id)->name ?? "",
                    'log' => $errorLog
                ];
            }
        }

        //Lỗi NG
        if (!isset($request->type) || ($request->type === 'loi_ng')) {
            $qc_history = $infoCongDoan->qcHistory;
            $groupErrorHistories = ErrorHistory::where('q_c_history_id', $qc_history->id ?? null)->get()->groupBy(function ($item) {
                return Carbon::parse($item->created_at)->format('Y-m-d H:i');
            });
            foreach ($groupErrorHistories as $errorHistories) {
                if (count($errorHistories) <= 0) {
                    continue;
                }
                $stt++;
                $errorLog = [];
                $quantity = 0;
                foreach ($errorHistories ?? [] as $index => $item) {
                    $errorLog[] = [
                        'key' => $index,
                        'id' => $item->id ?? null,
                        'error_id' => $item->error_id,
                        'quantity' => $item->quantity,
                    ];
                    $quantity += $item->quantity;
                }
                $errorList[] = [
                    'key' => $stt,
                    'stt' => $stt,
                    'type' => 'Lỗi NG',
                    'quantity' => $quantity,
                    'date' => Carbon::parse($errorHistories[0]->created_at ?? null)->format('d/m/Y H:i:s'),
                    'user_name' => CustomUser::find($errorHistories[0]->user_id ?? null)->name ?? "",
                    'log' => $errorLog
                ];
            }
        }

        //Khoanh vùng
        if (!isset($request->type) || ($request->type === 'khoanh_vung')) {
            $qc_history = $infoCongDoan->qcHistory;
            $groupYellowStampHistories = YellowStampHistory::where('q_c_history_id', $qc_history->id ?? null)->get()->groupBy(function ($item) {
                return Carbon::parse($item->created_at)->format('Y-m-d H:i');
            });
            foreach ($groupYellowStampHistories as $yellowStampHistories) {
                if (count($yellowStampHistories) <= 0) {
                    continue;
                }
                $stt++;
                $errorLog = [];
                $quantity = 0;
                foreach ($yellowStampHistories ?? [] as $index => $item) {
                    $errorLog[] = [
                        'key' => $index,
                        'id' => $item->id ?? null,
                        'error_id' => $item->errors,
                        'quantity' => $item->sl_tem_vang,
                    ];
                    $quantity += $item->sl_tem_vang;
                }
                $errorList[] = [
                    'key' => $stt,
                    'stt' => $stt,
                    'type' => 'Khoanh vùng',
                    'quantity' => $quantity,
                    'date' => Carbon::parse($yellowStampHistories[0]->created_at ?? null)->format('d/m/Y H:i:s'),
                    'user_name' => CustomUser::find($yellowStampHistories[0]->user_id ?? null)->name ?? "",
                    'log' => $errorLog
                ];
            }
        }

        usort($errorList, function ($a, $b) {
            return strtotime($a['date']) <=> strtotime($b['date']);
        });

        return $this->success(['errorList' => $errorList, 'infoCongDoan' => $infoCongDoan]);
    }

    public function updateDauNoi(Request $request)
    {
        $lot_error_log = LotErrorLog::find($request->id);
        if (!$lot_error_log) {
            return $this->failure([], "Không tìm thấy lỗi này");
        }
        $log = $lot_error_log->log ?? [];
        foreach ($log as $key => $value) {
            $log[$request->error_id] = $request->quantity ?? 0;
        }
        $lot_error_log->update([
            'log' => $log,
            'user_id' => $request->user()->id,
        ]);
        return $this->success($lot_error_log, "Đã cập nhật dấu nối");
    }

    public function deleteDauNoi(Request $request)
    {
        $lot_error_log = LotErrorLog::find($request->id);
        if (!$lot_error_log) {
            return $this->failure([], "Không tìm thấy lỗi này");
        }
        $log = $lot_error_log->log ?? [];
        foreach ($log as $key => $value) {
            unset($log[$request->error_id]);
        }
        if (empty($log)) {
            $lot_error_log->delete();
            return $this->success([], "Đã xoá dấu nối");
        } else {
            $lot_error_log->update([
                'log' => $log,
                'user_id' => $request->user()->id,
            ]);
        }
        return $this->success([], "Đã xoá dấu nối");
    }
    //============================Chất lượng============================
    //Số liệu tổng quan Chất lượng
    public function getQCOverall(Request $request)
    {
        $info_query = InfoCongDoan::whereDate('thoi_gian_ket_thuc', Carbon::today());
        $lot_plan_query = LotPlan::whereDate('end_time', Carbon::today());
        if (!empty($request->line_id)) {
            $info_query->where('line_id', $request->line_id);
            $lot_plan_query->where('line_id', $request->line_id);
        }
        if (!empty($request->machine_code)) {
            $info_query->where('machine_code', $request->machine_code);
            $lot_plan_query->where('machine_code', $request->machine_code);
        }
        $info_cong_doan = $info_query->get();
        $lot_plans = $lot_plan_query->get();
        $data = [];
        $data['ke_hoach'] = $lot_plans->sum('quantity');
        $data['muc_tieu'] = round(($data['ke_hoach'] / 12) * ((int)date('H') - 6));
        $data['ket_qua'] = $info_cong_doan->sum('sl_dau_ra_hang_loat');
        return $this->success($data);
    }

    //Trả về danh sách lot QC
    public function getLotQCList(Request $request)
    {
        if ($request->line_id == 30) {
            $query = QCHistory::where(function ($q) {
                $q->whereDate('scanned_time', date('Y-m-d'))->orWhere('eligible_to_end', 0);
            })->whereHas('infoCongDoan', function ($q) use ($request) {
                if (!empty($request->line_id)) {
                    $q->where('line_id', $request->line_id);
                }
                if (!empty($request->machine_code)) {
                    $q->where('machine_code', $request->machine_code);
                }
            });
        } else {
            $query = QCHistory::where(function ($q) {
                $q->whereDate('scanned_time', date('Y-m-d'))->orWhereHas('infoCongDoan', function ($info_query) {
                    $info_query->where('status', 1);
                });
            })->whereHas('infoCongDoan', function ($q) use ($request) {
                if (!empty($request->line_id)) {
                    $q->where('line_id', $request->line_id);
                }
                if (!empty($request->machine_code)) {
                    $q->where('machine_code', $request->machine_code);
                }
            });
        }
        $list = $query->orderBy('scanned_time', 'DESC')->get();
        $data = [];
        foreach ($list as $value) {
            $infoCongDoan = $value->infoCongDoan ?? null;
            $item = [];
            $item['info_cong_doan_id'] = $infoCongDoan->id ?? null;
            $item['ngay_sx'] = date('Y-m-d', strtotime($value->scanned_time));
            $item['lot_id'] = $infoCongDoan->lot_id ?? "";
            $item['ten_sp'] = $infoCongDoan->product->name ?? "";
            $item['product_id'] = $infoCongDoan->product_id ?? "";
            $item['lo_sx'] = $infoCongDoan->lo_sx ?? "";
            $item['sl_dau_ra_hang_loat'] = $infoCongDoan->sl_dau_ra_hang_loat ?? 0;
            $item['sl_ok'] = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
            $item['ty_le_ht'] = $infoCongDoan->sl_dau_ra_hang_loat > 0 ? round($infoCongDoan->sl_ok / $infoCongDoan->sl_dau_ra_hang_loat * 100) : 0;
            $item['sl_tem_vang'] = $infoCongDoan->sl_tem_vang ?? 0;
            $item['sl_ng'] = $infoCongDoan->sl_ng ?? 0;
            $item['qc_status'] = $value->eligible_to_end ?? 0;
            $item['status'] = $infoCongDoan->status ?? 0;
            $item['sl_kh'] = $infoCongDoan->sl_dau_vao_hang_loat ?? 0;
            $item['line_id'] = $infoCongDoan->line_id ?? "";
            $item['machine_code'] = $infoCongDoan->machine_code ?? "";
            $data[] = $item;
        }
        return $this->success($data);
    }

    public function getLotQCCurrent(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $infoCongDoans = InfoCongDoan::with('qcHistory')
            ->where('line_id', $line->id)
            ->where('machine_code', $machine->code)
            ->where('status', InfoCongDoan::STATUS_INPROGRESS)
            ->get();
        foreach ($infoCongDoans as $key => $infoCongDoan) {
            if ($infoCongDoan) {
                $infoCongDoan['info_cong_doan_id'] = $infoCongDoan->id ?? null;
                QCHistory::firstOrCreate(
                    [
                        'info_cong_doan_id' => $infoCongDoan->id,
                    ],
                    [
                        'scanned_time' => date('Y-m-d H:i:s'),
                        'user_id' => $request->user()->id,
                    ]
                );
            }
        }

        return $this->success($infoCongDoans);
    }

    //Scan lot vào QC
    public function scanQC(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($line->id == 29) {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $request->machine_code)->whereDate('created_at', date('Y-m-d'))->where('line_id', $line->id)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)
                ->where('line_id', $line->id)->where('machine_code', $machine->code)
                ->whereDate('created_at', date('Y-m-d'))
                ->first();
        }
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot");
        }
        $qcHistory = QCHistory::firstOrCreate(
            [
                'info_cong_doan_id' => $infoCongDoan->id,
            ],
            [
                'scanned_time' => date('Y-m-d H:i:s'),
                'user_id' => $request->user()->id,
            ]
        );
        if ($infoCongDoan) {
            return $this->success($qcHistory, "Quét QC thành công");
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    public function scanOQC(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $lot = Lot::where('id', $request->lot_id)->first();
        if (!$lot) {
            return $this->failure([], "Không tìm thấy lot");
        }
        $data = [];
        try {
            DB::beginTransaction();
            $infoCongDoan = InfoCongDoan::firstOrCreate(
                [
                    'lot_id' => $request->lot_id,
                    'product_id' => $lot->product_id,
                    'lo_sx' => $lot->lo_sx,
                    'line_id' => $line->id,
                ],
                [
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'user_id' => $request->user()->id,
                    'sl_dau_vao_hang_loat' => $lot->so_luong,
                    'sl_kh' => $lot->so_luong,
                    'status' => InfoCongDoan::STATUS_INPROGRESS
                ]
            );
            $qcHistory = QCHistory::firstOrCreate(
                [
                    'info_cong_doan_id' => $infoCongDoan->id,
                ],
                [
                    'scanned_time' => date('Y-m-d H:i:s'),
                    'user_id' => $request->user()->id,
                ]
            );
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi quét QC");
        }
        return $this->success($data, "Quét QC thành công");
    }

    public function filterTestCriteria($infoCongDoan)
    {
        $line = $infoCongDoan->line;
        $product = $infoCongDoan->product;
        $tc_query = $line->testCriteria();
        $list = $tc_query->get();
        $testCriteriaHistories = $infoCongDoan->qcHistory->testCriteriaHistories;
        $testCriteriaDetailHistories = $testCriteriaHistories->flatMap->testCriteriaDetailHistories ?? collect([]);
        $data = [];
        if ($line->id == '30') {
            $query = QCHistory::where(function ($q) {
                $q->whereDate('scanned_time', date('Y-m-d'))->orWhere('eligible_to_end', 0);
            })->whereHas('infoCongDoan', function ($q) use ($infoCongDoan) {
                $q->where('line_id', $infoCongDoan->line_id);
            });
        } else {
            $query = QCHistory::where(function ($q) {
                $q->whereDate('scanned_time', date('Y-m-d'))->orWhereHas('infoCongDoan', function ($info_query) {
                    $info_query->where('status', 1);
                });
            })->whereHas('infoCongDoan', function ($q) use ($infoCongDoan) {
                $q->where('line_id', $infoCongDoan->line_id);
                $q->where('machine_code', $infoCongDoan->machine_code);
            });
        }
        $qcHistories = $query->where('info_cong_doan_id', '!=', $infoCongDoan->id)->get();
        // return $qcHistories;
        $detailHistories = TestCriteriaDetailHistory::whereHas('testCriteriaHistory', function ($subQuery) use ($qcHistories) {
            $subQuery->whereIn('q_c_history_id', $qcHistories->pluck('id')->toArray());
        })->with('testCriteriaHistory.qcHistory.infoCongDoan')->get()->mapWithKeys(function ($detailHistory) {
            $product_id = $detailHistory->testCriteriaHistory->qcHistory->infoCongDoan->product_id ?? null;
            $machine_code = $detailHistory->testCriteriaHistory->qcHistory->infoCongDoan->machine_code ?? null;
            $test_criteria_name = $detailHistory->testCriteria->hang_muc ?? null;
            $info_lot_id = $detailHistory->testCriteriaHistory->qcHistory->infoCongDoan->lot_id ?? null;
            return [$product_id . $machine_code . $test_criteria_name => $info_lot_id];
        })
            ->toArray();
        // return $detailHistories;
        foreach ($list as $item) {

            $chi_tieu_slug = Str::slug($item->chi_tieu);
            if (!isset($data[$chi_tieu_slug]['data'])) {
                $data[$chi_tieu_slug]['data'] = [];
            }
            $history = $testCriteriaDetailHistories->first(function ($value) use ($item) {
                return $value->test_criteria_id == $item->id;
            });
            $item->value = $history['input'] ?? null;
            $item->result = $history['result'] ?? null;
            if (empty($item->result)) {
                if ($item->frequency === TestCriteria::MOT_MAU_TREN_MOT_CA) {
                    //Lọc theo sản phẩm và theo máy trong ca
                    $isExist = false;
                    // foreach ($detailHistory as $entry) {
                    //     if ($entry['product_id'] === $infoCongDoan->product_id && $entry['machine_code'] === $infoCongDoan->machine_code && $entry['test_criteria_name'] === $item->hang_muc) {
                    //         $isExist = true;
                    //     }
                    // }
                    if (isset($detailHistories[$infoCongDoan->product_id . $infoCongDoan->machine_code . $item->hang_muc])) {
                        $isExist = true;
                    };
                    if ($isExist) {
                        continue;
                    }
                }
            }
            // array_push($data[$chi_tieu_slug]['data'], $item);
            $parsedCriteria = $this->findSpec($item, $product);
            if ($parsedCriteria) array_push($data[$chi_tieu_slug]['data'], $parsedCriteria);
            $data[$chi_tieu_slug]['result'] = $testCriteriaHistories->firstWhere('type', $chi_tieu_slug)->result ?? null;
        }
        return $data;
    }

    public function getCriteriaListOfLot(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($line->id != '30') {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::with('qcHistory.testCriteriaHistories.testCriteriaDetailHistories')->where('lot_id', $request->lot_id)->where('machine_code', $request->machine_code)->where('line_id', $line->id)->first();
        } else {
            $infoCongDoan = InfoCongDoan::with('qcHistory.testCriteriaHistories.testCriteriaDetailHistories')->where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        }
        if (!$infoCongDoan) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        $data = $this->filterTestCriteria($infoCongDoan);
        $criteria_type = ['kich-thuoc', 'dac-tinh', 'ngoai-quan'];
        foreach ($criteria_type as $key => $type) {
            if ((empty($data[$type]) || empty($data[$type]['data'])) && $infoCongDoan->qcHistory) {
                TestCriteriaHistory::firstOrCreate(
                    ['q_c_history_id' => $infoCongDoan->qcHistory->id, 'type' => $type, 'user_id' => $infoCongDoan->qcHistory->user_id],
                    ['result' => 'OK']
                );
                $data[$type] = ['data' => [], 'result' => 'OK'];
            }
        }
        $counter = 0;
        foreach ($data as $key => $value) {
            if (isset($value['result']) && $value['result'] === 'OK') {
                $counter++;
            }
        }
        if ($counter >= 3) {
            $infoCongDoan->qcHistory && $infoCongDoan->qcHistory->update(['eligible_to_end' => 1]);
            if (!$infoCongDoan->sl_dau_ra_hang_loat) {
                $infoCongDoan->update(['sl_dau_ra_hang_loat' => $infoCongDoan->sl_dau_vao_hang_loat - $infoCongDoan->sl_ng]);
            }
        }
        return $this->success($data);
    }

    public function findSpec($test, $product)
    {
        $plusOrMinus = "±";
        $approximate = "~";
        $fromTo = "-";
        $hang_muc = Str::slug($test->hang_muc);
        $base_line_ids = $test->lines->pluck('id')->toArray();
        $reference = !empty($test->reference) ? explode(",", $test->reference) : [];
        if (in_array(29, $base_line_ids) || in_array(30, $base_line_ids)) {
            $reference = array_merge($reference, [26]); //Nếu chỉ tiêu thuộc công đoạn Chọn hoặc OQC thì tham chiếu công đoạn Bế (Giai đoạn 2)
        }
        $lines = array_merge($test->lines->pluck('id')->toArray(), $reference);
        $spec = Spec::whereIn("line_id", $lines)->where('slug', $hang_muc)->where("product_id", $product->id ?? "")->whereNotNull('name')->whereNotNull('value')->first();
        // if($test->chi_tieu === 'Đặc tính'){
        //     Log::info($test->hang_muc);
        //     // Log::info($spec);
        //     Log::info([$lines, $hang_muc, $product->id]);
        // }
        if (!$spec || trim($spec->value) === 'N/A') {
            return null;
        }
        if ($test["phan_dinh"] === 'Nhập số') {
            try {
                $extractValues = $this->detect_format($spec->value, $product);
                if ($extractValues) {
                    foreach ($extractValues as $key => $value) {
                        $test[$key] = $value;
                    }
                    $test['note'] = $spec->value;
                    $test["input"] = true;
                }
            } catch (\Throwable $th) {
                //throw $th;
            }
        }

        return $test;
    }

    function detect_format($input, $product = null)
    {
        $input = str_replace([',', ($product->name ?? '')], ['.', ''], $input);

        // Định dạng 1: '12.5+1.5/-1.25'
        $pattern1 = "/(-?\d+(\.\d+)?)([+-]\d+(\.\d+)?)?\/(-?\d+(\.\d+)?)/";

        // Định dạng 2: 106.47 ± 1.2
        $pattern2 = "/(-?\d+(\.\d+)?)\s*±\s*(-?\d+(\.\d+)?)/";

        // Định dạng 3: Khoảng dùng dấu '-'
        $pattern3 = "/(-?\d+(\.\d+)?)\s*-\s*(-?\d+(\.\d+)?)/";

        // Định dạng 4: Khoảng dùng dấu '~'
        $pattern4 = "/(-?\d+(\.\d+)?)\s*~\s*(-?\d+(\.\d+)?)/";

        // Định dạng 5: So sánh ('≥', '≤', '>', '<')
        $pattern5 = "/(≥|<=|≤|>=|>|<)\s*(-?\d+(\.\d+)?)/";

        // Loại bỏ phần mô tả nếu có trước giá trị số
        $input = trim(preg_replace("/.*?:\s*/", "", $input));

        if (preg_match($pattern1, $input, $matches)) {
            $value1 = (float)$matches[1] + (float)$matches[3];
            $value2 = (float)$matches[1] + (float)$matches[5];
            if ($value1 > $value2) {
                $max = $value1;
                $min = $value2;
            } else {
                $max = $value2;
                $min = $value1;
            }
            return [
                "type" => "Format 1 (X/Y/Z)",
                "min" => $min,
                "max" => $max,
            ];
        } elseif (preg_match($pattern2, $input, $matches)) {
            return [
                "type" => "Format 2 (± Tolerance)",
                "min" => (float)$matches[1] - (float)$matches[3],
                "max" => (float)$matches[1] + (float)$matches[3]
            ];
        } elseif (preg_match($pattern3, $input, $matches)) {
            return [
                "type" => "Format 3 (Range using '-')",
                "min" => (float)$matches[1],
                "max" => (float)$matches[3]
            ];
        } elseif (preg_match($pattern4, $input, $matches)) {
            return [
                "type" => "Format 4 (Range using '~')",
                "min" => (float)$matches[1],
                "max" => (float)$matches[3]
            ];
        } elseif (preg_match($pattern5, $input, $matches)) {
            $operator = $matches[1];
            $value = (float)$matches[2];

            // Xử lý theo từng dấu so sánh
            switch ($operator) {
                case "≥":
                case ">=":
                    return [
                        "type" => "Format 5 (Greater than or equal)",
                        "min" => $value,
                        "max" => PHP_INT_MAX // Không có giá trị tối đa
                    ];
                case "≤":
                case "<=":
                    return [
                        "type" => "Format 5 (Less than or equal)",
                        "min" => PHP_INT_MIN, // Không có giá trị tối thiểu
                        "max" => $value
                    ];
                case ">":
                    return [
                        "type" => "Format 5 (Greater than)",
                        "min" => $value + 0.0001, // Lớn hơn giá trị nên cộng 1 lượng nhỏ
                        "max" => PHP_INT_MAX
                    ];
                case "<":
                    return [
                        "type" => "Format 5 (Less than)",
                        "min" => PHP_INT_MIN,
                        "max" => $value - 0.0001 // Nhỏ hơn giá trị nên trừ 1 lượng nhỏ
                    ];
                default:
                    return null;
            }
        } else {
            return null;
        }
    }

    //Lưu kết quả QC
    public function savePQCResult(Request $request)
    {
        if (!isset($request->criteria_key)) {
            return $this->failure([], "Không tìm thấy tiêu chí");
        }
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($line->id == 29 || $line->id == 30) {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)
                ->where('line_id', $line->id)->where('machine_code', $machine->code)
                ->where('status', InfoCongDoan::STATUS_INPROGRESS)
                ->first();
        }
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot");
        }
        $qc_history = QCHistory::firstOrCreate(
            [
                "info_cong_doan_id" => $infoCongDoan->id,
            ],
            [
                'scanned_time' => date('Y-m-d H:i:s'),
                'user_id' => $request->user()->id
            ]
        );
        try {
            DB::beginTransaction();
            $test_criteria_history = TestCriteriaHistory::where(
                [
                    'q_c_history_id' => $qc_history->id,
                    'type' => Str::slug($request->criteria_key)
                ]
            )->first();
            if (!$test_criteria_history) {
                $test_criteria_history = TestCriteriaHistory::create([
                    'q_c_history_id' => $qc_history->id,
                    'user_id' => $request->user()->id,
                    'type' => Str::slug($request->criteria_key),
                    'result' => $request->result
                ]);
            } else {
                $test_criteria_history->update(
                    [
                        'user_id' => $request->user()->id,
                        'result' => $request->result
                    ]
                );
            }
            foreach (($request['data'] ?? []) as $data) {
                TestCriteriaDetailHistory::updateOrCreate(
                    [
                        'test_criteria_history_id' => $test_criteria_history->id,
                        'test_criteria_id' => $data['id'],
                    ],
                    [
                        'input' => $data['value'],
                        'result' => $data['result'],
                        'type' => Str::slug($request->criteria_key),
                    ]
                );
            }
            if ($request->result === 'OK') {
                $testCriteriaHistories = TestCriteriaHistory::where('q_c_history_id', $qc_history->id)->where('result', 'OK')->get();
                if (count($testCriteriaHistories) === 3) {
                    $qc_history->update(['eligible_to_end' => QCHistory::READY_TO_END]);
                    $qualityData = [
                        'machine_code' => $request->machine_code,
                        'lot_id' => $request->lot_id,
                        'is_check' => false,
                    ];
                    if ($line->id == 30) {
                        $infoCongDoan->update(['sl_dau_ra_hang_loat' => $infoCongDoan->sl_dau_vao_hang_loat - $infoCongDoan->sl_ng]);
                    }
                    broadcast(new QualityUpdated($qualityData));
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }
        return $this->success($qc_history, "Đã lưu kết quả QC");
    }

    public function updateErrorLog(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($request->line_id === '30') {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)
                ->where('line_id', $line->id)
                ->where('machine_code', $machine->code)
                ->where('status', InfoCongDoan::STATUS_INPROGRESS)
                ->first();
            if ($infoCongDoan && $machine) {
                $tracking = Tracking::where('lot_id', $infoCongDoan->lot_id)->where('machine_id', $machine->code)->first();
            }
        }
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot này hoặc đã hoàn thành sản xuất");
        }
        $qcHistory = QCHistory::where('info_cong_doan_id', $infoCongDoan->id)->first();
        if (!$qcHistory) {
            $qcHistory = QCHistory::create([
                'info_cong_doan_id' => $infoCongDoan->id,
                'user_id' => $request->user()->id,
                'eligible_to_end' => 0,
                'scanned_time' => now(),
                'line_id' => $infoCongDoan->line_id,
                'machine_id' => $infoCongDoan->machine_code,
            ]);
        }
        try {
            DB::beginTransaction();
            $sl_ng = $infoCongDoan->sl_ng ?? 0;
            $sl_dau_ra_hang_loat = $infoCongDoan->sl_dau_ra_hang_loat ?? 0;
            if ($sl_dau_ra_hang_loat == 0) {
                return $this->failure([], "Không có sl đầu ra hàng loạt");
            }
            $sl_tem_vang = $infoCongDoan->sl_tem_vang ?? 0;
            $permission = [];
            foreach ($request->user()->roles as $role) {
                $tm = ($role->permissions()->pluck("slug"));
                foreach ($tm as $t) {
                    $permission[] = $t;
                }
            }
            foreach ($request->log ?? $request->data as $key => $value) {
                if (!$value) {
                    continue;
                }
                ErrorHistory::create([
                    'q_c_history_id' => $qcHistory->id,
                    'error_id' => $key,
                    'quantity' => $value,
                    'user_id' => $request->user()->id,
                    'type' => count(array_intersect(['oqc', 'pqc'], $permission)) > 0 ? 'qc' : 'sx',
                ]);
                $sl_ng += ($value ?? 0);
            }
            $sl_con_lai = $sl_dau_ra_hang_loat - $sl_tem_vang - $sl_ng;
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng NG vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai === 0) {
                $infoCongDoan->update([
                    'sl_ng' => $sl_ng,
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                if ($tracking) {
                    $tracking->update([
                        'lot_id' => null,
                        'input' => 0,
                        'output' => 0
                    ]);
                }
                Lot::updateOrCreate(
                    ['id' => $infoCongDoan->lot_id],
                    [
                        'product_id' => $infoCongDoan->product_id,
                        'material_id' => $infoCongDoan->material_id,
                        'lo_sx' => $infoCongDoan->lo_sx,
                        'so_luong' => 0,
                        'final_line_id' => $line->id,
                    ]
                );
            } else {
                Assignment::where('lot_id', $infoCongDoan->lot_id)->update(['ok_quantity' => $sl_con_lai]);
                $infoCongDoan->update([
                    'sl_ng' => $sl_ng
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }
        return $this->success('', "Đã lưu kết quả quản lý lỗi");
    }

    public function updateTemVangQuantity(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($request->line_id === '29') {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->first();
        }
        if (!$infoCongDoan) {
            return $this->failure([], 'Không tìm thấy lot');
        }
        $qcHistory = QCHistory::where('info_cong_doan_id', $infoCongDoan->id)->first();
        if (!$qcHistory) {
            return $this->failure([], 'Chưa vào QC');
        }
        if (!$request->sl_tem_vang) {
            return $this->failure([], 'Không có số lượng tem vàng');
        }
        try {
            DB::beginTransaction();
            $sl_dau_ra = $infoCongDoan->sl_dau_ra_hang_loat;
            $sl_ng = $infoCongDoan->sl_ng;
            $sl_tem_vang = $infoCongDoan->sl_tem_vang;
            $sl_tem_vang += $request->sl_tem_vang;
            $sl_con_lai = $sl_dau_ra - $sl_ng - $sl_tem_vang;
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng Tem vàng vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai > 0) {
                return $this->failure([], "Số lượng Tem vàng phải bằng số lượng sản xuất");
            } else {
                $infoCongDoan->update([
                    'sl_tem_vang' => $sl_tem_vang
                ]);
            }
            $errorHistories = YellowStampHistory::create([
                'q_c_history_id' => $qcHistory->id,
                'errors' => implode(',', $request->seleted_errors ?? []),
                'sl_tem_vang' => $request->sl_tem_vang,
                'user_id' => $request->user()->id
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }
        return $this->success('', "Đã lưu kết quả QC");
    }

    public function updateKhoangVung(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($request->line_id === '30') {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->first();
        }
        if (!$infoCongDoan) {
            return $this->failure([], 'Không tìm thấy lot');
        }
        $qcHistory = QCHistory::where('info_cong_doan_id', $infoCongDoan->id)->first();
        if (!$qcHistory) {
            $qcHistory = QCHistory::create([
                'info_cong_doan_id' => $infoCongDoan->id,
                'user_id' => $request->user()->id,
                'eligible_to_end' => 0,
                'scanned_time' => now(),
                'line_id' => $infoCongDoan->line_id,
                'machine_id' => $infoCongDoan->machine_code,
            ]);
        }
        $sl_dau_ra = $infoCongDoan->sl_dau_ra_hang_loat;
        $sl_ng = $infoCongDoan->sl_ng;
        $sl_tem_vang = $infoCongDoan->sl_tem_vang;
        try {
            DB::beginTransaction();
            foreach ($request->log as $key => $value) {
                $khoanh_vung = YellowStampHistory::create([
                    'q_c_history_id' => $qcHistory->id,
                    'errors' => $key,
                    'sl_tem_vang' => $value,
                    'user_id' => $request->user()->id
                ]);
                $sl_tem_vang += $value;
            }
            $sl_con_lai = $sl_dau_ra - $sl_ng - $sl_tem_vang;
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng Tem vàng vượt quá số lượng sản xuất");
            }
            $infoCongDoan->update([
                'sl_tem_vang' => $sl_tem_vang
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }
        return $this->success('', 'Đã lưu dữ liệu khoanh vùng');
    }

    public function checkEligibleForPrinting($infoCongDoan)
    {
        $qcHistory = $infoCongDoan->qcHistory;
        if ($qcHistory && $qcHistory->eligible_to_end === 1) {
            return true;
        } else {
            return false;
        }
    }

    public function printTemVang(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $query = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id);
        $machine = Machine::where('code', $request->machine_code ?? null)->first();
        if ($machine) {
            $query->where('machine_code', $machine->code);
        }
        $infoCongDoan = $query->first();
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot");
        }
        if ($infoCongDoan->sl_tem_vang <= 0) {
            return $this->failure('', 'Không có số lượng tem vàng không thể in tem');
        }
        if (!$this->checkEligibleForPrinting($infoCongDoan)) {
            return $this->failure([], "Chưa kiểm tra đủ tiêu chí QC");
        }
        try {
            DB::beginTransaction();
            $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng Tem vàng vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai === 0) {
                Lot::updateOrCreate(['id' => $infoCongDoan->lot_id], [
                    'id' => $infoCongDoan->lot_id,
                    'product_id' => $infoCongDoan->product_id,
                    'material_id' => $infoCongDoan->material_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'so_luong' => $infoCongDoan->sl_tem_vang,
                    'final_line_id' => $line->id,
                    'type' => Lot::TYPE_TEM_VANG,
                ]);
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                $tracking = Tracking::where('lot_id', $infoCongDoan->lot_id)->where('machine_id', $machine->code ?? "")->first();
                if ($tracking) {
                    $tracking->update([
                        'lot_id' => null,
                        'input' => 0,
                        'output' => 0
                    ]);
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi in tem vàng");
        }
        return $this->success($this->formatTemVang($infoCongDoan, $request), "In tem vàng thành công");
    }

    public function formatTemVang($infoCongDoan, $request)
    {
        $product = $infoCongDoan->product;
        $line = $infoCongDoan->line;
        if ($line->id === 24) {
            $losx = $infoCongDoan->losx;
            if ($losx && $losx->product_id) {
                $product_id = $losx->product_id;
            } else {
                $bom = Bom::where('material_id', $infoCongDoan->product_id)->where('priority', 1)->first();
                if ($bom && $bom->product_id) {
                    $product_id = $bom->product_id;
                } else {
                    $product_id = $infoCongDoan->product_id;
                }
            }
        } else {
            $product_id = $infoCongDoan->product_id;
        }
        $product_journey = Spec::where('product_id', $product_id)->where('slug', 'hanh-trinh-san-xuat')->whereRaw('value REGEXP "^[0-9]+$"')->orderBy('value')->pluck('value', 'line_id');
        $currnetLineIndex = $product_journey[$infoCongDoan->line_id] ?? 0;
        $nextLineIds = collect($product_journey)
            ->filter(function ($value, $lineId) use ($currnetLineIndex) {
                return $value > $currnetLineIndex;
            })->keys();
        if (count($nextLineIds) > 0) {
            $next_line = Line::find($nextLineIds[0]);
        } else {
            $next_line = Line::where('ordering', '>', $line->ordering)->orderBy('ordering')->first();
        }
        $qc_history = $infoCongDoan->qcHistory;
        $user_sx = CustomUser::find($infoCongDoan->user_id ?? null);
        $user_qc = CustomUser::find($qc_history->user_id ?? null);
        $loi_tem_vang = YellowStampHistory::where('q_c_history_id', $qc_history->id ?? null)->pluck('errors')->toArray();
        $lotErrorLog = LotErrorLog::where('lot_id', $request->lot_id)->orderBy('line_id')->get();
        $log = [];
        foreach ($lotErrorLog as $item) {
            foreach ($item->log ?? [] as $key => $value) {
                $log[$key] = ($log[$key] ?? 0) + $value;
            }
        }
        $errors = [];
        foreach ($log as $key => $value) {
            $errors[] = "$key: $value";
        }
        $ghi_chu = "Hàng tem vàng - " . implode(',', $loi_tem_vang);
        $data = [];
        $data['lot_id'] = $infoCongDoan->lot_id ?? "";
        $data['lsx'] = $infoCongDoan->lo_sx ?? "";
        $data['ten_sp'] = $product->name ?? "";
        $data['sl_tem_vang'] = $infoCongDoan->sl_tem_vang ?? 0;
        $data['his'] = $product->his ?? "";
        $data['ver'] = $product->ver ?? "";
        $data['cd_thuc_hien'] = $line->name ?? "";
        $data['cd_tiep_theo'] = $next_line->name ?? "";
        $data['nguoi_sx'] = $user_sx->name ?? "";
        $data['nguoi_qc'] = $user_qc->name ?? "";
        $data['tinh_trang_loi'] = implode(', ', $errors);
        $data['ghi_chu'] = $ghi_chu;
        $data['machine_code'] = $infoCongDoan->machine_code ?? "";
        return $data;
    }


    //=======================================Kho hàng=======================================
    public function scanImport(Request $request)
    {
        $input = $request->all();
        $lot = Lot::where('id', $input['lot_id'])->first();
        if (!$lot) {
            return $this->failure([], "Mã thùng không tồn tại");
        }
        $check_lot = DB::table('cell_lot')->where('lot_id', $input['lot_id'])->count();
        if ($check_lot) {
            return $this->failure([], "Mã thùng đã có trong kho");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', 30)->first();
        if (!$infoCongDoan) {
            return $this->failure('', 'Chưa qua OQC');
        }
        $qc_history = QCHistory::where('info_cong_doan_id', $infoCongDoan->id)->first();
        if (!$qc_history || !$qc_history->eligible_to_end) {
            return $this->failure([], "Thùng này chưa qua OQC");
        }
        $data = new \stdClass();
        $product = Product::find($lot->product_id);
        $data->so_luong = $lot->so_luong;
        $data->khach_hang = $product->customer_id ?? "";
        $data->ten_san_pham = $product->name ?? "";
        $data->ma_thung = $input['lot_id'];

        $cell_check = Cell::where('product_id', $product->id)->count();
        $number_of_bin = 30;
        if ($product->chieu_rong_thung >= 340) {
            $number_of_bin = 29;
        }
        if ($cell_check === 0) {
            $cell = Cell::where('number_of_bin', 0)->whereNull('product_id')->orderBy('name', 'ASC')->first();
            if (!$cell) {
                $cell = Cell::where('number_of_bin', 0)->orderBy('name', 'ASC')->first();
            }
            if (!$cell) {
                return $this->failure('', 'Không còn vị trí phù hợp');
            }
            $data->vi_tri_de_xuat = $cell->id;
        } else {
            $cell_find = Cell::where('product_id', $product->id)->where('number_of_bin', '<', $number_of_bin)->orderBy('id', 'ASC')->first();
            if ($cell_find) {
                $data->vi_tri_de_xuat = $cell_find->id;
            } else {
                $cell_propose = Cell::where('number_of_bin', 0)->orderBy('id')->first();
                if ($cell_propose) {
                    $cell_propose->product_id = $product->id;
                    $cell_propose->save();
                    $data->vi_tri_de_xuat = $cell_propose->id;
                } else {
                    return $this->failure('', 'Không còn vị trí phù hợp');
                }
            }
        }
        return $this->success([$data]);
    }

    public function overallImport(Request $request)
    {
        $records = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->get();
        $lot_ids = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->pluck('lot_id')->toArray();
        $lo_sx = Lot::whereIn('id', $lot_ids)->pluck('lo_sx')->toArray();
        $tong_ma_hang = ProductionPlan::whereIn('lo_sx', $lo_sx)->distinct()->count('product_id');
        $so_luong = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->sum('so_luong');
        $data = ['sum_bin' => count($records), 'sum_bin_imported' => $tong_ma_hang, 'quantity' => $so_luong];
        return $this->success($data);
    }

    public function overallExport(Request $request)
    {
        $date = isset($request->date) ? date('Y-m-d', strtotime($request->date)) : date('Y-m-d');
        $exportPlans = WareHouseExportPlan::whereDate('ngay_xuat_hang', $date)->get();
        $sum_so_luong_kh = $exportPlans->sum('sl_yeu_cau_giao');
        $sum_so_luong_tt = $exportPlans->sum('sl_thuc_xuat');
        $ti_le = $sum_so_luong_kh != 0 ? number_format(($sum_so_luong_tt * 100) / $sum_so_luong_kh) . ' %' : 0;
        $data = ['number_of_plan' => $sum_so_luong_kh, 'quantity' => $sum_so_luong_tt, 'ratio' => $ti_le];
        return $this->success($data);
    }

    public function warehouseExportCustomer(Request $request)
    {
        $date = isset($request->date) ? date('Y-m-d', strtotime($request->date)) : date('Y-m-d');
        $product_ids = WareHouseExportPlan::whereDate('ngay_xuat_hang', $date)->pluck('product_id')->toArray();
        $customer_ids = Product::whereIn('id', $product_ids)->pluck('customer_id')->toArray();
        $customers = Customer::select('id as value', 'name as label')->whereIn('id', $customer_ids)->get();
        return $this->success($customers);
    }

    public function getProposeWarehouseExportList(Request $request)
    {
        $date = isset($request->date) ? date('Y-m-d', strtotime($request->date)) : date('Y-m-d');
        $product_ids = Product::where('customer_id', $request->khach_hang)->pluck('id')->toArray();
        $records = WareHouseExportPlan::with('inventory')->whereIn('product_id', $product_ids)
            ->whereDate('ngay_xuat_hang', $date)
            ->where(function ($subQuery) {
                $subQuery->has('approval')
                    ->orWhereHas('inventory', function ($query) {
                        $query->whereColumn('warehouse_export_plan.sl_yeu_cau_giao', '<=', 'inventory.sl_ton');
                    });
            })
            ->where(function ($query) {
                $query->whereColumn('sl_yeu_cau_giao', '>=', 'sl_thuc_xuat')
                    ->orWhereNull('sl_thuc_xuat');
            })->get();
        $data = [];
        $lot_arr = [];
        $month = Carbon::now()->subMonths(1)->month;
        $year = Carbon::now()->subMonths(1)->year;
        foreach ($records as $key => $record) {
            // $cell_ids = Cell::where('product_id', $record->product_id)->pluck('id')->toArray();
            $cell_lots = CellLot::whereHas('lot', function ($subQuery) use ($record) {
                $subQuery->where('product_id', $record->product_id);
            })
                // ->whereYear('created_at', $year)
                // ->whereMonth('created_at', '>=', $month)
                ->orderBy('created_at', 'ASC')
                ->get();
            if (count($cell_lots) == 0) {
                $object = new stdClass();
                $object->product_id = $record->product ? $record->product->id : '';
                $object->ten_san_pham = $record->product ? $record->product->name : '';
                $object->lot_id = 'Không có tồn';
                $object->ke_hoach_xuat = $record->sl_yeu_cau_giao;
                $object->thuc_te_xuat = $record->sl_thuc_xuat;
                $object->vi_tri = '-';
                $object->so_luong =  '-';
                $object->pic = '-';
                $data[] = $object;
            }
            $product = Product::find($record->product_id);
            $dinh_muc = 0;
            foreach ($cell_lots as $key => $cell_lot) {
                if (in_array($cell_lot->lot_id, $lot_arr)) {
                    continue;
                } else {
                    $lot_arr[] = $cell_lot->lot_id;
                }
                if ($dinh_muc <  ($record->sl_yeu_cau_giao - $record->sl_hang_le - $record->sl_thuc_xuat)) {
                    $lot = Lot::find($cell_lot->lot_id);
                    if ($lot->so_luong < $product->dinh_muc_thung) continue;
                    $object = new stdClass();
                    $object->product_id = $record->product->id;
                    $object->ten_san_pham = $record->product->name;
                    $object->lot_id = $cell_lot->lot_id;
                    $object->ke_hoach_xuat = $record->sl_yeu_cau_giao;
                    $object->thuc_te_xuat = $record->sl_thuc_xuat;
                    $object->vi_tri = $cell_lot->cell_id;
                    $object->so_luong =  $lot->so_luong;
                    $object->pic = '';
                    $data[] = $object;
                    $dinh_muc = $dinh_muc + $lot->so_luong;
                }
            }
            if ($record->sl_hang_le > 0 && $dinh_muc < ($record->sl_yeu_cau_giao - $record->sl_thuc_xuat)) {
                $lot_ids = Lot::where('product_id', $record->product_id)->where('so_luong', $record->sl_hang_le)->pluck('id');
                if ($lot_ids) {
                    $object = new stdClass();
                    $cell_lot1 = DB::table('cell_lot')->whereIn('lot_id', $lot_ids)->first();
                    if ($cell_lot1) {
                        $lot_le = Lot::find($cell_lot1->lot_id);
                        if ($cell_lot1) {
                            $object->product_id = $record->product->id;
                            $object->ten_san_pham = $record->product->name;
                            $object->lot_id = $lot_le->id;
                            $object->ke_hoach_xuat = $record->sl_yeu_cau_giao;
                            $object->thuc_te_xuat =  $record->sl_thuc_xuat;
                            $object->vi_tri =  $cell_lot1->cell_id;
                            $object->so_luong =  $lot_le->so_luong;
                            $object->pic = '';
                            $data[] = $object;
                        }
                    }
                }
            }
        }
        return $this->success($data);
    }

    //==============================================Hàm tạo lot demo
    public function createLotDemo(Request $request)
    {
        $product_id = $request->product_id;
        if (!$product_id) {
            return $this->failure([], "Không tìm thấy sản phẩm");
        }
        $bacth_id = date('ymd');
        $quantity = $request->quantity;
        if (!$quantity) {
            return $this->failure([], "Số lượng không hợp lệ");
        }
        $default_quantity_lot = 1000;
        $quantity_array = $this->getQuantityArray($quantity, $default_quantity_lot);
        $line_phase2 = Line::with('machine')->whereIn('id', [24, 25, 26, 27])->get();
        $counter = InfoCongDoan::where('lo_sx', $bacth_id)->count() + 1;
        try {
            DB::beginTransaction();
            foreach ($quantity_array as $key => $value) {
                foreach ($line_phase2 as $line) {
                    $infoCongDoan = InfoCongDoan::updateOrCreate([
                        'lo_sx' => $bacth_id,
                        'lot_id' => $bacth_id . '.L.' . str_pad(($key + $counter), 4, '0', STR_PAD_LEFT),
                        'product_id' => $product_id,
                        'machine_code' => $line->machine[0]->code,
                        'line_id' => $line->id,
                        'status' => InfoCongDoan::STATUS_PLANNED,
                    ]);
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi tạo lot");
        }
        return $this->success([], "Tạo lot");
    }
    function getQuantityArray($quantity, $default)
    {
        // Calculate the full parts and the remainder
        $fullParts = intdiv($quantity, $default);
        $remainder = $quantity % $default;
        // Create an array filled with full parts
        $result = array_fill(0, $fullParts, $default);
        // Add the remainder if it is not zero
        if ($remainder !== 0) {
            $result[] = $remainder;
        }
        return $result;
    }
}
