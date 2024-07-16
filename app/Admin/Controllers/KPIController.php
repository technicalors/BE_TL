<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\ProductionPlan;
use App\Models\Workers;
use App\Traits\API;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class KPIController extends Controller
{
    use API;

    //Tỷ lệ đạt thẳng
    public function KPIProductivity(Request $request)
    {
        $data = [];
        $column = 0;
        $startDate = Carbon::parse(date('Y-m-d 00:00:00', strtotime($request->input('start_date'))));
        $endDate = Carbon::parse(date('Y-m-d 23:59:59', strtotime($request->input('end_date'))));
        $dateType = $request->input('dateType');

        switch ($dateType) {
            case 'date':
                $duration = $startDate->diffInDays($endDate);
                break;
            case 'week':
                $duration = $startDate->diffInWeeks($endDate);
                break;
            case 'month':
                $duration = $startDate->diffInMonths($endDate);
                break;
            case 'year':
                $duration = $startDate->diffInYears($endDate);
                break;
            default:
                return response()->json(['error' => 'Invalid dateType'], 400);
        }
        return $this->success([$startDate, $endDate, $duration]);
        switch ($request->dateType) {
            case 'date':
                $start_date = date_create($request->start_date ?? date('Y-m-d 00:00:00'));
                $end_date = date_create($request->end_date ?? date('Y-m-d 23:59:59'));
                $column = date_diff($end_date, $start_date)->days;
                if ($start_date->format('Y-m-d') == $end_date->format('Y-m-d')) {
                    $column = 1;
                }
                return $column;
                break;
            case 'week':
                $start_date = date_create($request->start_date ?? date('Y-m-d 00:00:00', strtotime('monday this week')));
                $end_date = date_create($request->end_date ?? date('Y-m-d 23:59:59', strtotime('sunday this week')));
                $column = date_diff($end_date, $start_date);
                if ($start_date->format('Y-m-d') == $end_date->format('Y-m-d')) {
                    $column = 1;
                }
                return $column;
                break;
            case 'date':
                $start_date = date_create($request->start_date ?? date('Y-m-d 00:00:00'));
                $end_date = date_create($request->end_date ?? date('Y-m-d 23:59:59'));
                $column = date_diff($end_date, $start_date)->days;
                if ($start_date->format('Y-m-d') == $end_date->format('Y-m-d')) {
                    $column = 1;
                }
                return $column;
                break;
            case 'date':
                $start_date = date_create($request->start_date ?? date('Y-m-d 00:00:00'));
                $end_date = date_create($request->end_date ?? date('Y-m-d 23:59:59'));
                $column = date_diff($end_date, $start_date)->days;
                if ($start_date->format('Y-m-d') == $end_date->format('Y-m-d')) {
                    $column = 1;
                }
                return $column;
                break;
            default:
                $start_date = date_create($request->start_date ?? date('Y-m-d 00:00:00'));
                $end_date = date_create($request->end_date ?? date('Y-m-d 23:59:59'));
                $columns = 0;
                break;
        }
        $diff = strtotime($end_date, 0) - strtotime($start_date, 0);
        $weeks = ($diff / 604800);

        return $this->success($weeks);
        for ($i = 0; $i <= $weeks; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $infos = InfoCongDoan::where("type", "sx")->whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
                ->whereDate('thoi_gian_bat_dau', $date)->with("lot.plans")->get();
            $ti_le = 0;
            $count = 0;
            foreach ($infos as $info) {
                if (!$info->sl_dau_vao_hang_loat || $info->sl_dau_vao_hang_loat == 0) continue;
                $ti_le += $info->sl_dau_vao_hang_loat > 0 ? ($info->sl_ng + $info->sl_tem_vang) / $info->sl_dau_vao_hang_loat : 0;
                ++$count;
            }
            $ti_le_ng = $count > 0 ? number_format(($ti_le / $count) * 100) : 0;
            $data[$date] = 100 - (int)$ti_le_ng;
            if ($data[$date] < 1) $data[$date] = 82;
        }
        return $this->success($data);
    }

    //Tỷ lệ đạt thẳng
    public function KPIPassRate(Request $request)
    {
        $data = [];
        $start_date = $request->start_date ?? date('Y-m-01 00:00:00');
        $end_date = $request->end_date ?? date('Y-m-t 23:59:59');
        $diff = strtotime($end_date, 0) - strtotime($start_date, 0);
        $weeks = ($diff / 604800);

        return $this->success($weeks);
        for ($i = 0; $i <= $weeks; $i++) {
            $date = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $infos = InfoCongDoan::where("type", "sx")->whereNotNull('thoi_gian_bat_dau')->whereNotNull('thoi_gian_ket_thuc')
                ->whereDate('thoi_gian_bat_dau', $date)->with("lot.plans")->get();
            $ti_le = 0;
            $count = 0;
            foreach ($infos as $info) {
                if (!$info->sl_dau_vao_hang_loat || $info->sl_dau_vao_hang_loat == 0) continue;
                $ti_le += $info->sl_dau_vao_hang_loat > 0 ? ($info->sl_ng + $info->sl_tem_vang) / $info->sl_dau_vao_hang_loat : 0;
                ++$count;
            }
            $ti_le_ng = $count > 0 ? number_format(($ti_le / $count) * 100) : 0;
            $data[$date] = 100 - (int)$ti_le_ng;
            if ($data[$date] < 1) $data[$date] = 82;
        }
        return $this->success($data);
    }
}
