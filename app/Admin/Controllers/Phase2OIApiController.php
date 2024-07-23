<?php

namespace App\Admin\Controllers;

use App\Events\ProductionUpdated;
use App\Http\Controllers\Controller;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Lot;
use App\Models\LotErrorLog;
use App\Models\LSXLog;
use App\Models\Machine;
use App\Models\MachineStatus;
use App\Models\Material;
use App\Models\ProductionPlan;
use App\Models\QCHistory;
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
        $factory = Factory::find(2);
        if (!$factory) {
            return $this->failure([], "Không tìm thấy nhà máy");
        }
        $list = Line::where("display", "1")->where('factory_id', $factory->id)->orderBy('ordering', 'ASC')->get();
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
        $query = InfoCongDoan::with('lot', 'product', 'plan', 'lot.log')->with(['spec' => function ($query) {
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
            $hao_phi_sx = $spec[$item->product_id . $item->line_id . 'hao-phi-san-xuat-cac-cong-doan'] ?? null;
            $hao_phi_vao_hang = $spec[$item->product_id . $item->line_id . 'hao-phi-vao-hang-cac-cong-doan'] ?? null;
            $data =  [
                "lo_sx" => $item->lo_sx,
                "lot_id" => $item->lot_id,
                "ma_hang" => $item ? $item->product->id : '',
                "ten_sp" => $item ? $item->product->name : '',
                "ma_hang" => $item ? $item->product->id : '',
                "sl_ke_hoach" => $item->sl_kh ?? 0,
                'thoi_gian_bat_dau_kh' => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : "",
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : "", "",
                'thoi_gian_bat_dau' => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : "",
                'thoi_gian_ket_thuc' => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => 0,
                'sl_dau_ra_kh' => 0,
                'sl_dau_vao_hang_loat' => $item->sl_dau_vao_hang_loat ?? 0,
                'sl_dau_ra_hang_loat' => $item->sl_dau_ra_hang_loat ?? 0,
                "sl_dau_ra_ok" => $item->sl_dau_ra_hang_loat - $item->sl_tem_vang - $item->sl_ng,
                "sl_tem_vang" => $item->sl_tem_vang,
                "sl_ng" => $item->sl_ng,
                "ti_le_ht" => $item->sl_dau_ra_hang_loat > 0 ? round(($item->sl_dau_ra_hang_loat - $item->sl_ng - $item->sl_tem_vang) / $item->sl_dau_ra_hang_loat * 100) . '%' : "0%",
                "uph_an_dinh" => "",
                "uph_thuc_te" => "",
                "status" => $item->status,
                "nguoi_sx" => $item->lot->log->info[str::slug($line->name)]['user_name'] ?? "",
                "thoi_gian_bam_may" => $item->thoi_gian_bam_may,
                'hao_phi_cong_doan' => $hao_phi_sx ? $hao_phi_sx->value . "%" : "",
            ];
            if (in_array($line_id, $line_arr)) {
                $data['sl_dau_vao'] = $item->lot->product->so_bat > 0 ? $item->sl_dau_vao_hang_loat / $item->lot->product->so_bat : $item->sl_dau_vao_hang_loat;
                $data['sl_dau_ra'] = $item->lot->product->so_bat > 0 ? $item->sl_dau_ra_hang_loat / $item->lot->product->so_bat : $item->sl_dau_ra_hang_loat;
                $data['sl_tem_vang'] = $item->lot->product->so_bat > 0 ? $item->sl_tem_vang / $item->lot->product->so_bat : $item->sl_tem_vang;
                $data['sl_tem_ng'] = $item->lot->product->so_bat ? $item->sl_ng / $item->lot->product->so_bat : $item->sl_ng;
                // $data['sl_dau_ra_kh'] = $plan->sl_thanh_pham ?? '';
            } else {
                $data['sl_dau_vao'] = $item->sl_dau_vao_hang_loat;
                $data['sl_dau_ra'] = $item->sl_dau_ra_hang_loat;
                $data['sl_tem_vang'] = $item->sl_tem_vang;
                $data['sl_tem_ng'] = $item->sl_ng;
            }
            $data['sl_dau_ra_ok'] = $data['sl_dau_ra'] - $data['sl_tem_vang'] - $data['sl_tem_ng'];
            // sl_ng - spec_vao_hang / sl_dau_vao_hang_loat
            $data['hao_phi'] = $data['sl_dau_vao'] ? round((($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) > 0 ? ($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) : 0 / $data['sl_dau_vao']) * 100) . '%' : "";
            $records[] = $data;
        }
        return $this->success($records);
    }

    //Quét NVL vào công đoạn gấp dán
    public function scanMaterial(Request $request)
    {
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
        $product = $material->bom->product ?? null;
        if (!$product) {
            return $this->failure([], "Không tìm thấy sản phẩm");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $machine->line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_PLANNED)->first();
        if (!$infoCongDoan) {
            return $this->failure([], "Không tìm thấy lot cần chạy");
        }
        if($infoCongDoan->product_id !== $product->id){
            return $this->failure([], "Mapping không thành công");
        }
        try {
            DB::beginTransaction();
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
        foreach ($lotErrorLog as $item) {
            foreach ($item->log ?? [] as $key => $value) {
                if (!isset($log[$key])) {
                    $errorList[] = Error::where('id', $key)->first();
                }
                $log[$key] = ($log[$key] ?? 0) + $value;
            }
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

    //Kết thúc sản xuất lot
    public function endOfProduction(Request $request)
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
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($infoCongDoan) {
            try {
                DB::beginTransaction();
                $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
                if ($sl_con_lai < 0) {
                    return $this->failure([], "Số lượng sản xuất không hợp lệ");
                } elseif ($sl_con_lai === 0) {
                    Lot::create([
                        'id' => $infoCongDoan->lot_id,
                        'product_id' => $infoCongDoan->product_id,
                        'lo_sx' => $infoCongDoan->lo_sx,
                        'so_luong' => 0,
                        'type' => Lot::TYPE_TEM_TRANG
                    ]);
                }
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                Lot::create([
                    'id' => $infoCongDoan->lot_id,
                    'product_id' => $infoCongDoan->product_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'so_luong' => $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang,
                    'type' => Lot::TYPE_TEM_TRANG
                ]);
                $tracking->update([
                    'lot_id' => null,
                    'input' => 0,
                    'output' => 0
                ]);
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi kết thúc sản xuất");
            }
            return $this->success($this->formatTemTrang($infoCongDoan, $request), "Kết thúc sản xuất thành công");
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    public function formatTemTrang($infoCongDoan, $request)
    {
        $product = $infoCongDoan->product;
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
        $data['ten_sp'] = $product->name;
        $data['soluongtp'] = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
        $data['his'] = $product->his;
        $data['ver'] = $product->ver;
        $data['cd_thuc_hien'] = $line->name ?? "";
        $data['cd_tiep_theo'] = $next_line->name ?? "";
        $data['nguoi_sx'] = $user->name ?? "";
        $data['ghi_chu'] = $ghi_chu ?? "";
        return $data;
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
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $query = InfoCongDoan::where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->whereDate('thoi_gian_bat_dau', '>=', Carbon::today()->subDays(7));
        $list  = $query->get();
        foreach ($list as $item) {
            $product = $item->product;
            $item->ten_sp = $product->name;
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
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        $data = [];
        if ($infoCongDoan) {
            try {
                DB::beginTransaction();
                $qc_history = QCHistory::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->first();
                if ($qc_history) {
                    $data = $qc_history->log ?? null;
                } else {
                    $qc_history = QCHistory::create([
                        'lot_id' => $infoCongDoan->lot_id,
                        'line_id' => $infoCongDoan->line_id,
                        'machine_code' => $infoCongDoan->machine_code,
                        'user_id' => $request->user()->id,
                    ]);
                    $data = $qc_history->log ?? null;
                }
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi quét QC");
            }
            return $this->success($data, "Quét QC thành công");
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }

    public function getCriteriaListOfLot(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->first();
        $product =  $infoCongDoan->product;
        $list  = TestCriteria::where('line_id', $line->id)->where('is_show', 1)->get();
        $reference = array_merge($list->pluck('reference')->toArray(), [$line->id]);
        $spcec = Spec::whereIn("line_id", $reference)->whereNotNull('slug')->whereNotNull('name')->where("product_id", $product->id)->whereNotNull('value')->get();
        $data = [];
        $ct = [];
        foreach ($list as $item) {
            if (!isset($data[Str::slug($item->chi_tieu)])) {
                $data[Str::slug($item->chi_tieu)] = [];
            }
            if ($item->hang_muc == " ") continue;
            if ($this->findSpec($item, $spcec)) array_push($data[Str::slug($item->chi_tieu)], $this->findSpec($item, $spcec));
            $ct[Str::slug($item->chi_tieu)] = $item->chi_tieu;
        }
        return $this->success($data);
    }

    public function findSpec($test, $spcecs)
    {
        return $test;
    }

    public function savePQCResult(Request $request)
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
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        $qc_history = QCHistory::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->first();
        if (!$qc_history) {
            return $this->failure([], "Không tìm thấy lịch sử QC");
        }
        try {
            DB::beginTransaction();
            $log = $qc_history->log ?? [];
            $log[Str::slug($request->criteria_key)] = $request->data;
            $qc_history->update([
                'log' => $log
            ]);
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
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        $qc_history = QCHistory::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->first();
        if (!$qc_history) {
            return $this->failure([], "Không tìm thấy lịch sử QC");
        }
        try {
            DB::beginTransaction();
            $log = $qc_history->log ?? [];
            $permission = [];
            foreach ($request->user()->roles as $role) {
                $tm = ($role->permissions()->pluck("slug"));
                foreach ($tm as $t) {
                    $permission[] = $t;
                }
            }
            $ng_quantity = 0;
            foreach ($request->data as $err_quantity) {
                $ng_quantity += $err_quantity;
            }
            $errors_log = [
                'data' => $request->data,
                'user_id' => $request->user()->id,
                'type' => count(array_intersect(['oqc', 'pqc'], $permission)) > 0 ? 'qc' : 'sx',
                'thoi_gian_kiem_tra' => Carbon::now()
            ];
            if (!isset($log['errors'])) {
                $log['errors'] = [];
            }
            $log['errors'][] = $errors_log;
            $log['sl_ng'] = ($log['sl_ng'] ?? 0) + $ng_quantity;
            $qc_history->update([
                'log' => $log
            ]);
            $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_tem_vang - $log['sl_ng'];
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng NG vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai === 0) {
                $infoCongDoan->update([
                    'sl_ng' => $log['sl_ng'],
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                $tracking = Tracking::where('lot_id', $infoCongDoan->lot_id)->where('machine_id', $machine->id)->first();
                if ($tracking) {
                    $tracking->update([
                        'lot_id' => null,
                        'input' => 0,
                        'output' => 0
                    ]);
                }
                Lot::create([
                    'id' => $infoCongDoan->lot_id,
                    'product_id' => $infoCongDoan->product_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'so_luong' => 0,
                ]);
            } else {
                $infoCongDoan->update([
                    'sl_ng' => $log['sl_ng']
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }

        return $this->success($qc_history, "Đã lưu kết quả QC");
    }

    public function updateTemVangQuantity(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        $qc_history = QCHistory::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->first();
        if (!$qc_history) {
            return $this->failure([], "Không tìm thấy lịch sử QC");
        }
        try {
            DB::beginTransaction();
            $log = $qc_history->log ?? [];
            $log['sl_tem_vang'] = ($log['sl_tem_vang'] ?? 0) + $request->sl_tem_vang;
            $qc_history->update([
                'log' => $log
            ]);
            if (!isset($log['loi_tem_vang'])) {
                $log['loi_tem_vang'] = [];
            }
            $log['loi_tem_vang'][] = $request->seleted_errors;
            $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $log['sl_tem_vang'];
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng Tem vàng vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai > 0) {
                return $this->failure([], "Số lượng Tem vàng phải bằng số lượng sản xuất");
            } else {
                $infoCongDoan->update([
                    'sl_tem_vang' => $log['sl_tem_vang']
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, "Lỗi lưu kết quả QC");
        }

        return $this->success($qc_history, "Đã lưu kết quả QC");
    }

    public function checkEligibleForPrinting($infoCongDoan)
    {
        $list = TestCriteria::where('line_id', $infoCongDoan->line_id)->where('is_show', 1)->select('chi_tieu')->distinct()->get();
        $qc_history = QCHistory::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->first();
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
            return false;
        }
        return true;
    }

    public function printTemVang(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($infoCongDoan->sl_tem_vang <= 0) {
            return $this->failure('', 'Không có số lượng tem vàng không thể in tem');
        }
        $new_tem_vang_id = "";
        if (str_contains($infoCongDoan->lot_id, '.TV')) {
            $parts = explode('.', $infoCongDoan->lot_id);
            array_pop($parts);
            $string = implode('.', $parts);
            $new_tem_vang_id = $string . '.TV' . $line->id;
        } else {
            $new_tem_vang_id = $infoCongDoan->lot_id . '.TV' . $line->id;
        }
        if (!$this->checkEligibleForPrinting($request)) {
            return $this->failure([], "Chưa kiểm tra đủ tiêu chí QC");
        }
        try {
            DB::beginTransaction();
            $this->checkEligibleForPrinting($request);
            $sl_con_lai = $infoCongDoan->sl_dau_ra_hang_loat - $infoCongDoan->sl_ng - $infoCongDoan->sl_tem_vang;
            if ($sl_con_lai < 0) {
                return $this->failure([], "Số lượng Tem vàng vượt quá số lượng sản xuất");
            } elseif ($sl_con_lai === 0) {
                Lot::firstOrCreate([
                    'id' => $infoCongDoan->lot_id,
                    'product_id' => $infoCongDoan->product_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'so_luong' => 0,
                    'type' => Lot::TYPE_TEM_VANG,
                ]);
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                $tracking = Tracking::where('lot_id', $infoCongDoan->lot_id)->where('machine_id', $machine->id)->first();
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
        $qc_history = QCHistory::where('lot_id', $infoCongDoan->lot_id)->where('line_id', $infoCongDoan->line_id)->where('machine_code', $infoCongDoan->machine_code)->first();
        $user_sx = CustomUser::find($infoCongDoan->user_id);
        $user_qc = CustomUser::find($qc_history->user_id);
        // $errors = [];
        // if (isset($qc_history->log['errors'])) {
        //     foreach ($qc_history->log['errors'] as $error) {
        //         foreach ($error['data'] as $key => $err) {
        //             $errors[] = $key;
        //         }
        //     }
        // }
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
        $ghi_chu = "Hàng tem vàng - " . implode(', ', $errors);
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
        $data['ghi_chu'] = $ghi_chu;
        return $data;
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
