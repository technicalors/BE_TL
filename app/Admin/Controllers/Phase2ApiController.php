<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Lot;
use App\Models\Machine;
use App\Models\Material;
use App\Models\ProductionPlan;
use App\Models\Spec;
use App\Models\Tracking;
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

class Phase2ApiController extends Controller
{
    use API;

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
        $list = $query->orderBy('thoi_gian_bat_dau', 'DESC')->get();
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
                "dinh_muc" => $item ? $item->product->dinh_muc : '',
                "sl_ke_hoach" => 0,
                'thoi_gian_bat_dau_kh' => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : "",
                'thoi_gian_bat_dau' => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : "",
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : "", "",
                'thoi_gian_ket_thuc' => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => 0,
                'sl_dau_ra_kh' => 0,
                'sl_dau_vao' => "",
                'sl_dau_ra' => "",
                "sl_dau_ra_ok" => "",
                "sl_tem_vang" => "",
                "sl_tem_ng" => "",
                "ti_le_ht" => "",
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

    //Cập nhật dữ liệu sản xuất từ IOT
    public function updateProduction(Request $request)
    {
        $privateKey = "MIGfMA0GCSqGSIb3DQ";
        if ($request->private_key !== $privateKey) {
            return $this->failure([], "Incorrect private_key");
        }
        $machine = Machine::where('code', $request->machine_id)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $tracking = Tracking::where('machine_id', $machine->code)->where('status', 1)->first();
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        if ($tracking->lot_id) {
            if ($tracking->input === 0) {
                $tracking->update([
                    'input' => $request->input,
                    'output' => $request->output
                ]);
            } else {
                $tracking->update([
                    'output' => $request->output
                ]);
            }
            $infoCongDoan = InfoCongDoan::where('lot_id', $tracking->lot_id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
            if ($infoCongDoan) {
                if (!$infoCongDoan->thoi_gian_bam_may) {
                    //Vào hàng
                    if ($request->output - $tracking->input > 0) {
                        $infoCongDoan->update([
                            'sl_dau_vao_chay_thu' => $request->output - $tracking->input,
                        ]);
                    }
                } else {
                    //Hàng loạt
                    if ($request->output - $tracking->input > 0) {
                        $infoCongDoan->update([
                            'sl_dau_ra_hang_loat' => $request->output - $tracking->input - $infoCongDoan->sl_dau_ra_vao_hang,
                        ]);
                    }
                }
            } else {
                InfoCongDoan::create([
                    'lot_id' => $tracking->lot_id,
                    'line_id' => $machine->line_id,
                    'machine_code' => $machine->code,
                    'thoi_gian_bat_dau' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_INPROGRESS
                ]);
            }
            return $this->success([], "Cập nhật dữ liệu thành công");
        } else {
            return $this->failure([], "Không có lot nào được quét");
        }
    }

    //Quét NVL vào công đoạn gấp dán
    public function scanMaterial(Request $request)
    {
        $machine = Machine::where('code', $request->machine_code)->first();
        if (!$machine) {
            return $this->failure([], "Không tìm thấy máy");
        }
        $tracking = Tracking::where('machine_id', $machine->code)->where('status', 1)->first();
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $material = Material::with('bom.product')->find($request->material_id);
        if (!$material) {
            return $this->failure([], "Không tìm thấy NVL");
        }
        $product = $material->bom->product ?? null;
        if (!$product) {
            return $this->failure([], "Không tìm thấy sản phẩm");
        }
        $infoCongDoan = InfoCongDoan::where('machine_code', $machine->code)->where('line_id', $machine->line->id)->where('product_id', $product->id)->where('status', InfoCongDoan::STATUS_PLANNED)->orderBy('created_at')->first();
        if ($infoCongDoan) {
            try {
                DB::beginTransaction();
                $infoCongDoan->update([
                    'status' => InfoCongDoan::STATUS_INPROGRESS
                ]);
                $tracking->update([
                    'lot_id' => $infoCongDoan->lot_id
                ]);
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi quét NVL");
            }
        } else {
            return $this->failure([], "Kế hoạch sản xuất cho NVL này");
        }
        return $this->success([], "Bắt đầu sản xuất");
    }

    //Quét lot vào công đoạn
    public function scanManufacture(Request $request)
    {
        $tracking = Tracking::where('machine_id', $request->machine_id)->where('status', 1)->first();
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $check = InfoCongDoan::where('lot_id', $request->lot_id)->where('status', '<>', InfoCongDoan::STATUS_PLANNED)->first();
        if (!$check) {
            try {
                DB::beginTransaction();
                $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('status', InfoCongDoan::STATUS_PLANNED)->first();
                if (!$infoCongDoan) {
                    return $this->failure([], "Lot này chưa được sản xuất");
                }
                $tracking->update([
                    'lot_id' => $request->lot_id,
                    'input' => 0
                ]);
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi quét lot");
            }
        } else {
            return $this->failure([], "Lot này đã được sản xuất");
        }
        return $this->success([], "Quét lot thành công");
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
        $tracking = Tracking::where('machine_id', $machine->code)->where('status', 1)->first();
        if (!$tracking) {
            return $this->failure([], "Máy này chưa được sử dụng");
        }
        $infoCongDoan = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', $line->id)->where('machine_code', $machine->code)->where('status', InfoCongDoan::STATUS_INPROGRESS)->first();
        if ($infoCongDoan) {
            try {
                DB::beginTransaction();
                $infoCongDoan->update([
                    'thoi_gian_ket_thuc' => Carbon::now(),
                    'status' => InfoCongDoan::STATUS_COMPLETED
                ]);
                $tracking->update([
                    'lot_id' => null,
                    'input' => 0,
                ]);
                $lot = Lot::create([
                    'id' => $infoCongDoan->lot_id,
                    'product_id' => $infoCongDoan->product_id,
                    'lo_sx' => $infoCongDoan->lo_sx,
                    'so_luong'=> $infoCongDoan->sl_dau_ra_hang_loat,
                    'type'=> Lot::TYPE_TEM_TRANG,
                ]);
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return $this->failure($th, "Lỗi kết thúc sản xuất");
            }
            return $this->success([], "Kết thúc sản xuất thành công");
        } else {
            return $this->failure([], "Không tìm thấy lot");
        }
    }









    //Hàm tạo lot demo
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
