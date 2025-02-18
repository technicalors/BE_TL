<?php

namespace App\Admin\Controllers;

use App\Exports\Production\ProductOrderExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductOrderImport;
use App\Models\Inventory;
use App\Models\Line;
use App\Models\LineInventories;
use App\Models\Lot;
use App\Models\MachinePriorityOrder;
use App\Models\NumberMachineOrder;
use App\Models\ProductionOrderHistory;
use App\Models\ProductionOrderPriority;
use App\Models\ProductOrder;
use App\Models\Spec;
use App\Traits\API;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProductionOrderPriorityController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ProductionOrderPriority::orderBy('priority');
        if (isset($request->start_date) && isset($request->end_date)) {
            $query->whereDate('confirm_date', '>=', date('Y-m-d', strtotime($request->start_date)))
                ->whereDate('confirm_date', '<=', date('Y-m-d', strtotime($request->end_date)));
        }
        $total = $query->count();
        $result = $query->with(['productionOrderHistory.line', 'productionOrder', 'product'])->get();
        foreach ($result as $value) {
            $value->customer_name = $value->productionOrder->customer->name ?? "";
            $value->product_name = $value->product->name ?? "";
            $value->quantity = $value->productionOrder->quantity ?? "";
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function show($id)
    {
        $stamp = ProductionOrderPriority::find($id);

        if (!$stamp) {
            return $this->success('', 'Production Order Priorities not found');
        }

        return $this->success($stamp);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        DB::beginTransaction();
        try {
            Log::debug($input);
            $result = ProductionOrderPriority::create($input);
            DB::commit();
            return $this->success($result, 'Tạo thành công');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->success($e->getMessage(), 'Thao tác thất bại');
        }
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        try {
            DB::beginTransaction();
            $result = ProductionOrderPriority::find($id)->update($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Cập nhật thành công');
    }

    public function delete(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $result = ProductionOrderPriority::find($id)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Xoá thành công');
    }
}
