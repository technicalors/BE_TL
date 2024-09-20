<?php

namespace App\Http\Controllers;

use App\Imports\FcPlantImport;
use App\Imports\WarehouseExportPlanImport;
use App\Models\FcPlant;
use App\Models\MachineShift;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\WareHouseExportPlan;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class WarehouseExportPlanController extends Controller
{
    use API;

    public function index(Request $request)
    {
        $pageSize = $request->pageSize ?? 25;
        $query = WareHouseExportPlan::query()->orderByDesc('ngay_xuat_hang');

        if (isset($request->start_date)) {
            $query->whereDate('ngay_xuat_hang', '>=', $request->start_date);
        }

        if (isset($request->end_date)) {
            $query->whereDate('ngay_xuat_hang', '<=', $request->end_date);
        }

        $records = $query->paginate($pageSize);
        return $this->success([
            'data' => $records->items(),
            'paginate' => [
                'page' => $records->currentPage(),
                'page_size' => $pageSize,
                'total_items' => $records->total(),
                'total_pages' => $records->lastPage(),
            ]
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        DB::beginTransaction();
        try {
            Excel::import(new WarehouseExportPlanImport, $request->file('file'));
            DB::commit();
            return $this->success([], 'UPLOAD THÀNH CÔNG', 201);
        } catch (Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }
}
