<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Phase2DBApiController extends Controller
{
    use API;
    public function fmb(Request $request)
    {
        $lines = Line::where('factory_id', 2)->get();
        foreach ($lines as $line) {


            $info = InfoCongDoan::where("type", "sx")->where("line_id", $line)->with(["lot.plans", "lot.plan.product"])->orderBy('thoi_gian_bat_dau', 'DESC')->first();
            $plan = $info->lot->getPlanByLine($info->line_id);
            $product = $info->lot->product;
            if (!isset($plan)) $plan = $info->lot->plan;
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
            $tm = [
                "cong_doan" => mb_strtoupper($info->line->name, 'UTF-8'),
                "product" => $product ? $product->name : '',
                "sl_dau_ra_kh" => $plan ? ($plan->sl_thanh_pham ? $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0,
                "sl_thuc_te" => $info->sl_dau_ra_hang_loat - $info->sl_ng,
                "sl_muc_tieu" => $plan->sl_thanh_pham ? $plan->sl_thanh_pham : $plan->sl_giao_sx,
                "ti_le_ng" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ?  number_format(($info->sl_ng /  $info->sl_dau_ra_hang_loat), 2) : 0)),
                "ti_le_ht" => (int) (100 * ($plan->sl_thanh_pham > 0 ? number_format((($info->sl_dau_ra_hang_loat - $info->sl_ng) / $plan->sl_thanh_pham), 2) : 0)),
                "status" => $status,
                "time" => $info->updated_at,
            ];
            $tm['ti_le_ht'] = (int) (100 * (($tm['sl_dau_ra_kh']) > 0 ? number_format(($tm['sl_thuc_te'] / ($tm['sl_dau_ra_kh'])), 2) : 0));
            $res[] = $tm;
        }
    }
}
