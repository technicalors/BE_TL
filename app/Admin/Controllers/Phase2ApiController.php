<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Machine;
use App\Models\ProductionPlan;
use App\Models\Spec;
use App\Models\Workers;
use App\Traits\API;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Phase2ApiController extends Controller
{
    use API;
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

    public function getLotProductionList(Request $request)
    {
        $line_arr = [10, 11, 22, 12, 14];
        $line_id = $request->line_id;
        $line = Line::find($line_id);
        $machine_code = $request->machine_code;
        $date  = date('Y-m-d', strtotime('-5 day'));
        $query = InfoCongDoan::with('lot.product', 'plan', 'lot.log')->with(['spec' => function ($query) {
            $query->where('name', 'Hao phí sản xuất các công đoạn (%)');
        }])->whereDate('thoi_gian_bat_dau', '>=', $date);
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
            $plan = $item->lot->getPlanByLine($line_id);
            if (!$plan) {
                $plan = $item->plan;
            }
            $hao_phi_sx = $spec[$item->product_id . $item->line_id . 'hao-phi-san-xuat-cac-cong-doan'] ?? null;
            $hao_phi_vao_hang = $spec[$item->product_id . $item->line_id . 'hao-phi-vao-hang-cac-cong-doan'] ?? null;
            $data =  [
                "lo_sx" => $item->lot->lo_sx,
                "lot_id" => $item->lot->id,
                "ma_hang" => $item->lot ? $item->lot->product->id : '',
                "ten_sp" => $item->lot ? $item->lot->product->name : '',
                "dinh_muc" => $item->lot ? $item->lot->product->dinh_muc : '',
                "sl_ke_hoach" => $plan->sl_nvl ?? 0,
                'thoi_gian_bat_dau_kh' => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : "",
                'thoi_gian_bat_dau' => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : "",
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : "", "",
                'thoi_gian_ket_thuc' => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => $plan ? $plan->sl_nvl ?? $plan->sl_giao_sx : 0,
                'sl_dau_ra_kh' =>  $plan ? ($plan->sl_thanh_pham ? $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0,
                'sl_dau_vao' => "",
                'sl_dau_ra' => "",
                "sl_dau_ra_ok" => "",
                "sl_tem_vang" => "",
                "sl_tem_ng" => "",
                "ti_le_ht" => "",
                "uph_an_dinh" => $plan->UPH ?? "",
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
}
