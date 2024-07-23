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
        $lines = Line::where('factory_id', 2)->whereNot('id', 29)->get();
        $data = [];
        foreach ($lines as $line) {
            $info = InfoCongDoan::where("line_id", $line->id)->with(["lot.plans", "lot.plan.product"])->orderBy('thoi_gian_bat_dau', 'DESC')->first();
            if (!$info) {
                $tm = [
                    "cong_doan" => mb_strtoupper($line->name, 'UTF-8'),
                    "product" => '',
                    "sl_dau_ra_kh" => $info->sl_kh ?? 0,
                    "sl_thuc_te" => 0,
                    "sl_muc_tieu" => 0,
                    "ti_le_ng" => 0,
                    "ti_le_ht" => 0,
                    "status" => 0,
                    "time" => "",
                    'ti_le_ht' => 0
                ];
                $data[] = $tm;
            } else {


                // $plan = $info->lot->getPlanByLine($info->line_id);
                $product = $info->product ?? null;
                // if (!isset($plan)) $plan = $info->lot->plan;
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
                    "sl_dau_ra_kh" => $info->sl_kh ?? 0,
                    "sl_thuc_te" => $info->sl_dau_ra_hang_loat - $info->sl_ng,
                    "sl_muc_tieu" => $info->sl_kh,
                    "ti_le_ng" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ?  number_format(($info->sl_ng /  $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "ti_le_ht" => (int) (100 * ($info->sl_dau_ra_hang_loat > 0 ? number_format((($info->sl_dau_ra_hang_loat - $info->sl_ng) / $info->sl_dau_ra_hang_loat), 2) : 0)),
                    "status" => $status,
                    "time" => $info->updated_at,
                ];
                $tm['ti_le_ht'] = (int) (100 * (($tm['sl_dau_ra_kh']) > 0 ? number_format(($tm['sl_thuc_te'] / ($tm['sl_dau_ra_kh'])), 2) : 0));
                $data[] = $tm;
            }
        }
        return $this->success($data);
    }
}
