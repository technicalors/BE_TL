<?php

namespace App\Admin\Controllers;

use App\Events\ProductionUpdated;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Cell;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\ErrorHistory;
use App\Models\ErrorLot;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Lot;
use App\Models\LotErrorLog;
use App\Models\LSXLog;
use App\Models\Machine;
use App\Models\MachineStatus;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\QCDetailHistory;
use App\Models\QCHistory;
use App\Models\Spec;
use App\Models\TestCriteria;
use App\Models\TestCriteriaDetailHistory;
use App\Models\TestCriteriaHistory;
use App\Models\Tracking;
use App\Models\User;
use App\Models\Workers;
use App\Models\YellowStampHistory;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $list = Line::where("display", "1")
            // ->where('factory_id', 2)
            ->orderBy('ordering', 'ASC')
            ->get();
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
        if (isset($request->line)) {
            $line = Line::with(['machine:id,code,name,line_id'])->find($request->line);
            return $this->success($line->machine);
        } else {
            $machine = Machine::select('id', 'code', 'name')->get();
            return $this->success($machine);
        }
    }

    //Trả về dữ liệu tổng quan của sản xuất
    public function getProductionOverall(Request $request)
    {
        $tong_sl_thuc_te = 0;
        $tong_sl_ng = 0;
        $tong_sl_tem_vang = 0;
        $query = InfoCongDoan::whereDate("created_at", Carbon::now());
        if (empty($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        if (empty($request->line_id)) {
            $query->where('line_id', $request->line_id);
        }
        $info_cong_doans = $query->get();
        foreach ($info_cong_doans as $key => $info_congdoan) {
            $lot = Lot::find($info_congdoan->lot_id);
            $tong_sl_thuc_te += $info_congdoan->sl_dau_ra_hang_loat;
            $tong_sl_ng += $info_congdoan->sl_ng;
            $tong_sl_tem_vang += $info_congdoan->sl_tem_vang;
        }

        $data =  [
            "tong_sl_trong_ngay_kh" => 0,
            "tong_sl_thuc_te" =>  $tong_sl_thuc_te,
            "tong_sl_tem_vang" =>  $tong_sl_tem_vang,
            "tong_sl_ng" => $tong_sl_ng,
        ];
        $data['ty_le_hoan_thanh'] = $data['tong_sl_trong_ngay_kh'] > 0 ? round($data['tong_sl_thuc_te'] / $data['tong_sl_trong_ngay_kh'] * 100) . "%" : "%";
        return $this->success($data);
    }

    //Trả về sanh sách Lot sản xuất của công đoạn
    public function getLotProductionList(Request $request)
    {
        $line_arr = [10, 11, 22, 12, 14];
        $line_id = $request->line_id;
        $line = Line::find($line_id);
        $machine_code = $request->machine_code;
        $date  = date('Y-m-d', strtotime('-5 day'));
        $query = InfoCongDoan::with('lot', 'product.materialWastages', 'product.timeWastages', 'plan', 'material')->with(['spec' => function ($query) {
            $query->where('name', 'Hao phí sản xuất các công đoạn (%)');
        }]);
        if (!empty($request->line_id)) {
            $query->where('line_id', $line_id);
        }
        if (!empty($request->machine_code)) {
            $query->where('machine_code', $machine_code);
        }
        $list = $query->orderBy('lot_id', 'ASC')->get();
        $spec = Spec::whereIn('product_id', $list->pluck('product_id')->toArray())
            ->select('value', 'product_id', 'line_id', 'slug')->get()
            ->keyBy(function ($item) {
                return $item->product_id . $item->line_id . $item->slug;
            });
        $records = [];
        foreach ($list as $item) {
            $plan = $item->plan;
            $product = $item->product;
            if ($product && $product->materialWastages) {
                $hao_phi_sx = $product->materialWastages->first(function ($record) use ($item) {
                    return $record->line_id == $item->line_id && $record->type == 2;
                }) ?? null;
            } else {
                $hao_phi_sx = null;
            }
            if ($product && $product->timeWastages) {
                $hao_phi_vao_hang = $product->timeWastages->first(function ($record) use ($item) {
                    return $record->line_id == $item->line_id && $record->type == 1;
                }) ?? null;
            } else {
                $hao_phi_vao_hang = null;
            }
            $data =  [
                "lo_sx" => $item->lo_sx,
                "lot_id" => $item->lot_id,
                "ma_hang" => $item->product->id ?? '',
                "ten_sp" => $item->product->name ?? '',
                "ma_hang" => $item->product->id ?? '',
                "sl_ke_hoach" => $item->sl_kh ?? 0,
                'thoi_gian_bat_dau_kh' => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : "",
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : "",
                "",
                'thoi_gian_bat_dau' => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : "",
                'thoi_gian_ket_thuc' => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => $item->sl_kh ?? 0,
                'sl_dau_ra_kh' => $item->sl_kh ?? 0,
                'sl_dau_vao_hang_loat' => (int)$item->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra_hang_loat' => (int)$item->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_ra_ok" => $item->sl_dau_ra_hang_loat - $item->sl_tem_vang - $item->sl_ng,
                "sl_tem_vang" => $item->sl_tem_vang,
                "sl_ng" => $item->sl_ng,
                "ti_le_ht" => $item->sl_dau_ra_hang_loat > 0 ? round(($item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang) / $item->sl_kh * 100) . '%' : "0%",
                "uph_an_dinh" => $plan->UPH ?? 0,
                "uph_thuc_te" => 0,
                "status" => $item->status,
                "nguoi_sx" => $item->lot->log->info[str::slug($line->name)]['user_name'] ?? "",
                "thoi_gian_bam_may" => $item->thoi_gian_bam_may,
                'hao_phi_cong_doan' => $hao_phi_sx ? $hao_phi_sx->value . "%" : "",
                'sl_dau_vao' => $item->sl_dau_vao_hang_loat,
                'sl_dau_ra' => $item->sl_dau_ra_hang_loat,
                'sl_tem_vang' => $item->sl_tem_vang,
                'sl_tem_ng' => $item->sl_ng,
            ];
            if ($item->line_id == 24) {
                $data['adu'] = 'dcm';
                $data['ten_sp'] = $item->material->name ?? "";
                $data['ma_hang'] = $item->material->id ?? "";
            }
            $data['sl_dau_ra_ok'] = $data['sl_dau_ra'] - $data['sl_tem_vang'] - $data['sl_tem_ng'];
            $data['hao_phi'] = $data['sl_dau_vao'] ? round((($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) > 0 ? ($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) : 0 / $data['sl_dau_vao']) * 100) . '%' : "";
            $records[] = $data;
        }
        return $this->success($records);
    }

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
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        if ($tracking->lot_id) {
            return $this->failure([], "Máy này đang sản xuất");
        }
        $material = Material::with('bom.product')->find($request->material_id);
        if (!$material) {
            return $this->failure([], "Không tìm thấy NVL");
        }
        $infoCongDoan = null;
        if ($line->id === 24) {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('material_id', $material->id)->where('line_id', $machine->line_id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_PLANNED)->first();
        } else {
            $product_ids = $material->boms()->pluck('product_id')->toArray() ?? [];
            if (count($product_ids) === 0) {
                return $this->failure([], "Không tìm thấy sản phẩm");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $machine->line_id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_PLANNED)->first();
            if (!in_array($infoCongDoan->product_id, $product_ids)) {
                return $this->failure([], "Không tìm thấy lot phù hợp với mã NVL được quét");
            }
        }
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot cần chạy");
        }
        try {
            DB::beginTransaction();
            MachineStatus::reset($machine->code);
            $infoCongDoan->update([
                'thoi_gian_bat_dau' => Carbon::now(),
                'user_id' => $request->user()->id,
                'status' => InfoCongDoan::STATUS_INPROGRESS
            ]);
            $tracking->update([
                'lot_id' => $infoCongDoan->lot_id,
                'input' => 0,
                'output' => 0
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi quét NVL");
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
        $tracking = Tracking::where('machine_id', $machine->code)->first();
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        if ($tracking->lot_id && $tracking->lot_id !== $request->lot_id) {
            return $this->failure([], "Máy này đang sản xuất lot khác");
        }
        $check = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->where('status', '<>', InfoCongDoan::STATUS_PLANNED)->first();
        if (!$check) {
            try {
                DB::beginTransaction();
                MachineStatus::reset($machine->code);
                $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->where('status', InfoCongDoan::STATUS_PLANNED)->first();
                if (!$infoCongDoan) {
                    if (str_contains($request->lot_id, '.TV')) {
                        $previousLine = Line::where('ordering', '<', $machine->line->ordering)->orderBy('ordering', 'desc')->first();
                        $checkInfo = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $previousLine->id)->where('status', InfoCongDoan::STATUS_PLANNED)->first();
                        if ($checkInfo) {
                            $infoCongDoan = InfoCongDoan::create([
                                'lot_id' => $request->lot_id,
                                'line_id' => $machine->line->id,
                                'machine_code' => $machine->code,
                                'product_id' => $checkInfo->product_id,
                                'lo_sx' => $checkInfo->lo_sx,
                                'sl_kh' => $checkInfo->sl_kh,
                                'status' => InfoCongDoan::STATUS_PLANNED
                            ]);
                        } else {
                            return $this->failure([], "Lot chưa có kế hoạch sản xuất tại công đoạn này");
                        }
                    } else {
                        return $this->failure([], "Lot chưa có kế hoạch sản xuất tại công đoạn này");
                    }
                }
                if (!$infoCongDoan) {
                    return $this->failure([], "Lot chưa có kế hoạch sản xuất tại công đoạn này");
                }
                $infoCongDoan->update([
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'user_id' => $request->user()->id,
                    'status' => InfoCongDoan::STATUS_INPROGRESS
                ]);
                $tracking->update([
                    'lot_id' => $request->lot_id,
                    'input' => 0,
                    'output' => 0
                ]);
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi quét lot");
            }
        } else {
            return $this->failure([], "Lot này đã được quét");
        }
        return $this->success([], "Quét lot thành công");
    }

    public function getLotErrorLogList(Request $request)
    {
        $lotErrorLog = LotErrorLog::where('lot_id', $request->lot_id)->orderBy('line_id')->get();
        $errorList = [];
        $log = [];
        $index = 0;
        foreach ($lotErrorLog as $item) {
            foreach ($item->log ?? [] as $key => $value) {
                $log[$key] = ($log[$key] ?? 0) + $value;
            }
        }
        foreach ($log as $key => $value) {
            $error = Error::find($key);
            if (!$error) {
                continue;
            }
            $errorList[] = [
                'id' => $error->id,
                'noi_dung' => Error::find($key)->noi_dung,
                'value' => $value
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
            return $this->failure([], "Không tìm thấy mã lỗi ở công đoạn này");
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
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($infoCongDoan) {
            try {
                DB::beginTransaction();
                $log = LotErrorLog::where('lot_id', $request->lot_id)->where('machine_code', $machine->code)->where('line_id', $machine->line->id)->first();
                if ($log) {
                    $log->update([
                        'log' => $request->log
                    ]);
                } else {
                    $log = LotErrorLog::create([
                        'lot_id' => $infoCongDoan->lot_id,
                        'log' => $request->log,
                        'lo_sx' => $infoCongDoan->lo_sx,
                        'machine_code' => $infoCongDoan->machine_code,
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


    //Kết thúc sản xuất lot
    public function endOfProduction(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($line->id == 29) {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $tracking = Tracking::where('machine_id', $machine->code)->first();
            if (!$tracking) {
                return $this->failure([], "Máy này chưa được sử dụng");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        }

        if ($infoCongDoan) {
            if (!$this->checkEligibleForPrinting($infoCongDoan)) {
                return $this->failure([], "Chưa kiểm tra đủ tiêu chí QC");
            }
            try {
                DB::beginTransaction();
                $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
                if ($sl_con_lai < 0) {
                    return $this->failure([], "Số lượng sản xuất không hợp lệ");
                } elseif ($sl_con_lai === 0) {
                    $lot = Lot::find($infoCongDoan->lot_id);
                    if (!$lot) {
                        Lot::create([
                            'id' => $infoCongDoan->lot_id,
                            'product_id' => $infoCongDoan->product_id,
                            'material_id' => $infoCongDoan->material_id,
                            'lo_sx' => $infoCongDoan->lo_sx,
                            'so_luong' => 0,
                            'type' => Lot::TYPE_TEM_TRANG
                        ]);
                    } else {
                        $lot->update([
                            'so_luong' => 0
                        ]);
                    }
                } else {
                    $lot = Lot::find($infoCongDoan->lot_id);
                    if (!$lot) {
                        Lot::create([
                            'id' => $infoCongDoan->lot_id,
                            'product_id' => $infoCongDoan->product_id,
                            'material_id' => $infoCongDoan->material_id,
                            'lo_sx' => $infoCongDoan->lo_sx,
                            'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang,
                            'type' => Lot::TYPE_TEM_TRANG
                        ]);
                    } else {
                        $lot->update([
                            'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang
                        ]);
                    }
                }
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                if(isset($machine) && isset($tracking)){
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
                return $this->failure($th, "Lỗi kết thúc sản xuất");
            }
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    public function formatTemTrang($infoCongDoan, $request)
    {
        $product = $infoCongDoan->product;
        $material = $infoCongDoan->material;
        $line = $infoCongDoan->line;
        $next_line = Line::where('ordering', '>', $line->ordering)->orderBy('ordering')->first();
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
        $data['nguoi_sx'] = $user->name ?? "";
        $data['ghi_chu'] = $ghi_chu ?? "";
        return $data;
    }

    //San lot khi vào công đoạn chọn
    public function scanForSelectionLine(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $lot = Lot::find($request->lot_id);
        if (!$lot) {
            return $this->failure([], "Lot này chưa được sản xuất");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        if ($infoCongDoan) {
            return $this->failure([], "Đã quét lot này");
        }
        try {
            DB::beginTransaction();
            InfoCongDoan::create([
                'lot_id' => $request->lot_id,
                'lo_sx' => $lot->lo_sx,
                'line_id' => $line->id,
                'product_id' => $lot->product_id,
                'sl_kh' => $lot->so_luong,
                'sl_dau_vao_hang_loat' => $lot->so_luong,
                'thoi_gian_bat_dau' => Carbon::now(),
                'user_id' => $request->user()->id,
                'status' => InfoCongDoan::STATUS_INPROGRESS
            ]);
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
        $lot = Lot::find($request->lot_id);
        if (!$lot) {
            return $this->failure([], "Không tìm thấy lot");
        }
        $assignment = Assignment::with(['worker:id,name', 'lot'])->where('lot_id', $request->lot_id)->get();
        foreach ($assignment as $item) {
            $item['so_luong'] = $item->lot->so_luong ?? 0;
        }
        return $this->success($assignment);
    }

    //Tạo dữ liệu cho bảng Assignment
    public function createAssignment(Request $request)
    {
        $lot = Lot::find($request->lot_id);
        if (!$lot) {
            return $this->failure([], "Không tìm thấy lot");
        }
        try {
            DB::beginTransaction();
            $assignment = Assignment::updateOrCreate(
                ['lot_id' => $request->lot_id],
                [
                    'lot_id' => $request->lot_id,
                    'assigned_quantity' => $lot->so_luong,
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
            $infoCongDoan = InfoCongDoan::where('lot_id', $assignment->lot_id)->where('line_id', $request->line_id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
            if ($infoCongDoan) {
                $infoCongDoan->update([
                    'sl_dau_vao_hang_loat' => $request->actual_quantity ?? 0,
                    'sl_dau_ra_hang_loat' => $request->ok_quantity ?? 0,
                ]);
                $lot = Lot::where('id', $assignment->lot_id)->update([
                    'so_luong' => $request->ok_quantity ?? 0,
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            //throw $th;
            DB::rollBack();
            return $this->failure($th, "Lỗi xoá giao việc");
        }

        return $this->success($assignment);
    }

    //In tem tại công đoạn Chọn
    public function printTemSelectionLine(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $lot = Lot::find($request->lot_id);
        if (!$lot) {
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
        $data = [];
        try {
            DB::beginTransaction();
            $counter = floor($lot->so_luong / $request->sl_in_tem);
            if ($counter < 0) {
                return $this->failure([], "Số lượng in tem không hợp lệ");
            }
            if ($lot->so_luong === $request->sl_in_tem) {
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
            }
            $quantity = 0;
            $counterT = Lot::where('id', 'like', $lot->id . '-T%')->count() + 1;
            for ($i = 0; $i < $counter; $i++) {
                $id = $lot->id . '-T';
                $thung = Lot::firstOrCreate([
                    'id' => $id . ($i + $counterT),
                    'product_id' => $lot->product_id,
                    'material_id' => $lot->material_id,
                    'lo_sx' => $lot->lo_sx,
                    'so_luong' => $request->sl_in_tem,
                    'type' => Lot::TYPE_THUNG
                ]);
                $quantity += $request->sl_in_tem;
                $data[] = $this->formatTemChon($thung, $infoCongDoan);
            }
            $lot->update([
                'so_luong' => $lot->so_luong - $quantity
            ]);
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
        $data['lot_id'] = $lot->id;
        $data['lsx'] = $lot->lo_sx;
        $data['ten_sp'] = $product->name ?? $material->name ?? "";
        $data['soluongtp'] = $lot->so_luong;
        $data['his'] = $product->his ?? "";
        $data['ver'] = $product->ver ?? "";
        $data['cd_thuc_hien'] = $line->name ?? "";
        $data['cd_tiep_theo'] = $next_line->name ?? "";
        $data['nguoi_sx'] = $user->name ?? "";
        $data['ghi_chu'] = $ghi_chu ?? "";
        return $data;
    }

    public function startMassProduction(Request $request)
    {
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('machine_code', $request->machine_code)->where('line_id', $request->line_id)->first();
        if (!$infoCongDoan) {
            return $this->failure('', 'Không tìm thấy lot');
        }
        try {
            DB::beginTransaction();
            $infoCongDoan->update(['thoi_gian_bam_may' => date('Y-m-d H:i:s')]);
            DB::commit();
            return $this->success('', 'Kết thúc chạy thử. Bắt đầu tính sản lượng hàng loạt');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure('', $th->getMessage());
        }
    }
    //============================Chất lượng============================
    //Số liệu tổng quan Chất lượng
    public function getQCOverall(Request $request)
    {
        $query = InfoCongDoan::whereDate('thoi_gian_ket_thuc', Carbon::today());
        if (empty($request->line_id)) {
            $query->where('line_id', $request->line_id);
        }
        $info_cong_doan = $query->get();
        $data = [];
        $data['ke_hoach'] = 0;
        $data['muc_tieu'] = round(($data['ke_hoach'] / 12) * ((int)date('H') - 6));
        $data['ket_qua'] = $info_cong_doan->sum('sl_dau_ra_hang_loat');
        return $this->success($data);
    }

    //Trả về danh sách lot QC
    public function getLotQCList(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $query = InfoCongDoan::where('line_id', $line->id)
            ->where('status', InfoCongDoan::STATUS_INPROGRESS)
            ->whereDate('created_at', '>=', Carbon::today()->subDays(30));
        $machine = Machine::where('code', $request->machine_code)->first();
        if ($machine) {
            $query->where('machine_code', $machine->code);
        }
        $list  = $query->with('product', 'material')->get();
        foreach ($list as $item) {
            if ($item->line_id == 24) {
                $item->ten_sp = $item->material->name ?? "";
                $item->product_id = $item->material_id ?? "";
            } else {
                $item->ten_sp = $item->product->name ?? "";
                $item->product_id = $item->product_id ?? "";
            }
            $item->ngay_sx = $item->created_at->format('d/m/Y');
            $item->sl_kh = 0;
            $item->sl_ok = $item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang;
            $item->ty_le_ht = $item->sl_dau_ra_hang_loat > 0 ? round($item->sl_ok / $item->sl_dau_ra_hang_loat * 100) : 0;
        }
        return $this->success($list);
    }

    //Scan lot vào QC
    public function scanQC(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        if ($line->id == 29) {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
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
            return $this->failure([], "Không tìm lot");
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
            $qc_history = QCHistory::where('lot_id', $lot->id)->where('line_id', $request->line_id)->first();
            if ($qc_history) {
                $data = $qc_history->log ?? null;
            } else {
                $qc_history = QCHistory::create([
                    'lot_id' => $infoCongDoan->lot_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'line_id' => $infoCongDoan->line_id,
                    'user_id' => $request->user()->id,
                    'scanned_time' => Carbon::now(),
                ]);
                $data = $qc_history->log ?? null;
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi quét QC");
        }
        return $this->success($data, "Quét QC thành công");
    }

    public function getCriteriaListOfLot(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $infoCongDoan = InfoCongDoan::with('qcHistory.testCriteriaHistories.testCriteriaDetailHistories')->where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        $product = $infoCongDoan->product;
        $list = TestCriteria::where('line_id', $line->id)->whereRaw("NOT hang_muc <= ''")->where('is_show', 1)->get()->groupBy('chi_tieu');
        $reference = array_merge($list->pluck('reference')->toArray(), [$line->id]);
        $specs = Spec::whereIn("line_id", $reference)->whereNotNull('slug')->whereNotNull('name')->where("product_id", $product->id ?? "")->whereNotNull('value')->get();
        $data = [];
        foreach ($list as $key => $test_criteria) {
            $chi_tieu_slug = Str::slug($key);
            if (!isset($data[$chi_tieu_slug]['data'])) {
                $data[$chi_tieu_slug]['data'] = [];
            }
            $testCriteriaHistory = $infoCongDoan->qcHistory->testCriteriaHistories->first(function ($value) use ($chi_tieu_slug) {
                return $value->type === $chi_tieu_slug;
            });
            $qcDetailHistories = $testCriteriaHistory->testCriteriaDetailHistories ?? collect();
            foreach ($test_criteria as $item) {
                $history = $qcDetailHistories->first(function ($value) use ($item) {
                    return $value->test_criteria_id == $item->id;
                });
                $item->value = $history['input'] ?? null;
                $item->result = $history['result'] ?? null;
                $parsedCriteria = $this->findSpec($item, $specs);
                if ($parsedCriteria) array_push($data[$chi_tieu_slug]['data'], $parsedCriteria);
            }
            $data[$chi_tieu_slug]['result'] = $testCriteriaHistory->result ?? null;
        }
        return $this->success($data);
    }

    public function findSpec($test, $specs)
    {
        $find = "±";
        // return $test;
        $hang_muc = Str::slug($test->hang_muc);
        $spec = null;
        if (count($specs) > 0) {
            $spec = $specs->toQuery()->where("slug", 'like', "%$hang_muc%")->where('value', 'like', "%$find%")->first();
        }
        if ($spec) {
            $filtered_value = preg_replace('/-\D+/', '', $spec->value);
            $arr = explode($find, $filtered_value);
            $test["input"] = true;
            $test["tieu_chuan"] = filter_var($arr[0], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $test["delta"] =  filter_var($arr[1], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $test['note'] = $spec->value;
            return $test;
        }
        $test['input'] = false;
        return $test;
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
            return $this->failure([], "Không tìm lot");
        }
        $qc_history = QCHistory::where("info_cong_doan_id", $infoCongDoan->id)->first();
        if (!$qc_history) {
            return $this->failure([], "Chưa quét vào QC");
        }
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
            $criteria_key = [];
            $criteria = TestCriteria::where('line_id', $line->id)->whereRaw("NOT hang_muc <= ''")->where('is_show', 1)->get()->groupBy('chi_tieu');
            foreach ($criteria as $key => $value) {
                $criteria_key[] = Str::slug($key);
            }
            $testCriteriaHistories = TestCriteriaHistory::where('q_c_history_id', $qc_history->id)->whereIn('type', $criteria_key)->where('result', 'OK')->get();
            if (count($testCriteriaHistories) === count($criteria_key)) {
                $qc_history->update(['eligible_to_end' => QCHistory::READY_TO_END]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }
        return $this->success($qc_history, "Đã lưu kết quả QC");
    }

    public function savePQCResultForNormalLine(Request $request)
    {
        if (!isset($request->criteria_key)) {
            return $this->failure([], "Không tìm thấy tiêu chí");
        }
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)
            ->where('line_id', $line->id)->where('machine_code', $machine->code)
            // ->where('status', InfoCongDoan::STATUS_INPROGRESS)
            ->first();
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm lot");
        }
        $qc_history = QCHistory::where("info_cong_doan_id", $infoCongDoan->id)->first();
        if (!$qc_history) {
            return $this->failure([], "Chưa quét vào QC");
        }
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

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }
        return $this->success($qc_history, "Đã lưu kết quả QC");
    }

    public function savePQCResultForSelectionLine(Request $request)
    {
        if (!isset($request->criteria_key)) {
            return $this->failure([], "Không tìm thấy tiêu chí");
        }
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm lot");
        }
        try {
            DB::beginTransaction();
            $qc_history = QCHistory::where(
                [
                    'lot_id' => $infoCongDoan->lot_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'line_id' => $infoCongDoan->line_id,
                    'machine_code' => $infoCongDoan->machine_code,
                    'type' => Str::slug($request->criteria_key),
                ]
            )->first();
            if (!$qc_history) {
                $qc_history = QCHistory::create([
                    'lot_id' => $infoCongDoan->lot_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'line_id' => $infoCongDoan->line_id,
                    'machine_code' => $infoCongDoan->machine_code,
                    'type' => Str::slug($request->criteria_key),
                    'user_id' => $request->user()->id,
                    'result' => $request->result
                ]);
            } else {
                $qc_history->update(
                    [
                        'user_id' => $request->user()->id,
                        'result' => $request->result
                    ]
                );
            }
            if (!$qc_history) {
                return $this->failure([], "Không tìm thấy lịch sử QC");
            }
            foreach (($request['data'] ?? []) as $data) {
                QCDetailHistory::updateOrCreate(
                    ['test_criteria_id' => $data['id'], 'q_c_history_id' => $qc_history->id],
                    [
                        'input' => $data['value'],
                        'result' => $data['result'],
                    ]
                );
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
        if ($request->line_id === '29') {
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
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
            $tracking = Tracking::where('lot_id', $infoCongDoan->lot_id)->where('machine_id', $machine->id)->first();
        }
        if (!$infoCongDoan) {
            return $this->failure([], 'Không tìm thấy lot');
        }
        $qcHistory = QCHistory::where('info_cong_doan_id', $infoCongDoan->id)->first();
        if (!$qcHistory) {
            return $this->failure([], 'Chưa vào QC');
        }
        try {
            DB::beginTransaction();
            $sl_ng = $infoCongDoan->sl_ng ?? 0;
            $permission = [];
            foreach ($request->user()->roles as $role) {
                $tm = ($role->permissions()->pluck("slug"));
                foreach ($tm as $t) {
                    $permission[] = $t;
                }
            }
            foreach ($request->data as $key => $value) {
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
            $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_tem_vang - $sl_ng;
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng NG vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai === 0) {
                $infoCongDoan->update([
                    'sl_ng' => $sl_ng,
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                if (isset($tracking)) {
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
                    ]
                );
            } else {
                $infoCongDoan->update([
                    'sl_ng' => $sl_ng
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
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
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        } else {
            $machine = Machine::where('code', $request->machine_code)->first();
            if (!$machine) {
                return $this->failure([], "Không tìm thấy máy");
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
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
            $sl_tem_vang = $infoCongDoan->sl_tem_vang;
            $sl_tem_vang += $request->sl_tem_vang;
            $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $sl_tem_vang;
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

    public function checkEligibleForPrinting($infoCongDoan)
    {
        $qcHistory = $infoCongDoan->qcHistory;
        if ($qcHistory->eligible_to_end) {
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
        $query = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('status', InfoCongDoan::STATUS_INPROGRESS);
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
                    'type' => Lot::TYPE_TEM_VANG,
                ]);
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                $tracking = Tracking::where('lot_id', $infoCongDoan->lot_id)->where('machine_id', $machine->id ?? "")->first();
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
        $next_line = Line::where('ordering', '>', $line->ordering)->orderBy('ordering')->first();
        $qc_history = $infoCongDoan->qcHistory;
        $user_sx = CustomUser::find($infoCongDoan->user_id);
        $user_qc = CustomUser::find($qc_history->user_id);
        $loi_tem_vang = YellowStampHistory::where('q_c_history_id', $qc_history->id)->pluck('errors')->toArray();
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
        return $data;
    }


    //=======================================Kho hàng=======================================
    public function scanImport(Request $request)
    {
        $input = $request->all();
        $lot = Lot::where('id', $input['lot_id'])->where('type', Lot::TYPE_THUNG)->first();
        if (!$lot) {
            return $this->failure([], "Mã thùng không tồn tại");
        }
        $check_lot = DB::table('cell_lot')->where('lot_id', $input['lot_id'])->count();
        if ($check_lot) {
            return $this->failure([], "Mã thùng đã có trong kho");
        }
        $qc_history = QCHistory::where('lot_id', $lot->id)->where('line_id', 30)->first();
        if (!$qc_history) {
            return $this->failure([], "Thùng này chưa qua OQC");
        } else {
            $list = TestCriteria::where('line_id', 30)->where('is_show', 1)->select('chi_tieu')->distinct()->get();
            $qc_history = QCHistory::where('lot_id', $lot->id)->where('line_id', 30)->first();
            $log = [];
            if ($qc_history) {
                $log = $qc_history->log ?? [];
            }
            $result = [];
            foreach ($list as $item) {
                if (isset($log[Str::slug($item->chi_tieu)])) {
                    $qc_data = $log[Str::slug($item->chi_tieu)];
                    $result[] = $qc_data['result'] ?? "";
                }
            }
            if (in_array(0, $result) || count($result) !== count($list)) {
                return $this->failure([], "Thùng này chưa qua OQC");
            }
        }
        $data = new \stdClass();
        $product = Product::find($lot->product_id);
        $data->so_luong = $lot->so_luong;
        $data->khach_hang = $product->customer_id ?? "";
        $data->ten_san_pham = $product->name ?? "";
        $data->ma_thung = $input['lot_id'];

        $cell_check = Cell::where('product_id', $product->id)->count();
        $number_of_bin = 5;
        if ($product->chieu_rong_thung >= 340) {
            $number_of_bin = 4;
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
