<?php

namespace App\Http\Controllers;

use App\Imports\FcPlantImport;
use App\Imports\WarehouseExportPlanImport;
use App\Models\ApprovalWarehouseExportPlan;
use App\Models\FcPlant;
use App\Models\LineInventories;
use App\Models\WareHouseExportPlan;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class WarehouseExportPlanController extends Controller
{
    use API;

    public function index(Request $request)
    {
        $pageSize = $request->pageSize ?? 25;
        $query = WareHouseExportPlan::with('approval')->orderByDesc('ngay_xuat_hang');

        if (isset($request->start_date)) {
            $query->whereDate('ngay_xuat_hang', '>=', $request->start_date);
        }

        if (isset($request->end_date)) {
            $query->whereDate('ngay_xuat_hang', '<=', $request->end_date);
        }

        $records = $query->paginate($pageSize);
        $data = [];
        foreach ($records->items() as $key => $value) {
            $ton_kho = LineInventories::where('product_id', $value->product_id)->orderBy('updated_at', 'DESC')->first();
            $value->ton_kho = $ton_kho->quantity ?? 0;
            $data[] = $value;
        }
        return $this->success([
            'data' => $data,
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
    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'sl_yeu_cau_giao' => 'required',
        ]);
        $record = WareHouseExportPlan::find($request->id);
        if (!$record) {
            return $this->failure([], 'Không tìm thấy bản ghi', 404);
        }
        $record->update($request->all());
        return $this->success($record, 'Cập nhật thành công');
    }

    public function delete(Request $request)
    {
        $request->validate([
            'id' => 'required',
        ]);
        $record = WareHouseExportPlan::find($request->id);
        if (!$record) {
            return $this->failure([], 'Không tìm thấy bản ghi', 404);
        }
        $record->delete();
        return $this->success([], 'Xóa thành công');
    }

    public function approval(Request $request)
    {
        $request->validate([
            'id' => 'required',
        ]);
        $record = WareHouseExportPlan::find($request->id);
        if (!$record) {
            return $this->failure([], 'Không tìm thấy bản ghi', 404);
        }
        ApprovalWarehouseExportPlan::firstOrCreate([
            'warehouse_export_plan_id' => $record->id
        ], [
            'approver_id' => $request->user()->id
        ]);
        return $this->success($record, 'Duyệt thành công');
    }
}
