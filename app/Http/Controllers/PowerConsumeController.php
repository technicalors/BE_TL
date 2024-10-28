<?php

namespace App\Http\Controllers;

use App\Models\PowerConsume;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PowerConsumeController extends Controller
{
    use API;

    public function monthlyConsumption(Request $request)
    {
        $query = PowerConsume::select(
            DB::raw('DATE(`date`) as date'),
            DB::raw('SUM(`end_value` - `start_value`) as total_consumption')
        )
            ->groupBy(DB::raw('DATE(`date`)'))
            ->orderBy('date', 'asc');

        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }

        if (isset($request->datetime)) {
            $month = Carbon::parse($request->datetime)->format('m');
            $year = Carbon::parse($request->datetime)->format('Y');
            $query->whereMonth('date', $month)->whereYear('date', $year);
        }
            
        $result = $query->get();

        return $this->success($result);
    }
}
