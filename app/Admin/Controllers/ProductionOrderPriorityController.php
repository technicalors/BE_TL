<?php

namespace App\Admin\Controllers;

use App\Exports\Production\ProductOrderExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductOrderImport;
use App\Models\Inventory;
use App\Models\Line;
use App\Models\LineInventories;
use App\Models\Losx;
use App\Models\Lot;
use App\Models\MachinePriorityOrder;
use App\Models\NumberMachineOrder;
use App\Models\Product;
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
        $total = $query->count();
        $result = $query->with(['productionOrderHistory.line', 'productionOrder', 'product.inventory'])->get();
        foreach ($result as $value) {
            $value->customer_name = $value->productionOrder->customer->name ?? 0;
            $value->product_name = $value->product->name ?? 0;
            $value->quantity = $value->productionOrder->quantity ?? 0;
            $value->inventory_quantity = $value->product->inventory->sl_ton ?? 0;
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

    public function reorder(Request $request)
    {
        $input = $request->all();
        try {
            DB::beginTransaction();
            $keys = array_keys($input);
            $records = Losx::whereIn('id', $keys)->get();
            foreach ($records as $value) {
                $target = $input[$value->id] ?? 0;
                $value->priority = $target;
                $value->save();
            }
            $i = 1;
            foreach ($records as $o) {
                $o->priority = $i;
                $o->save();
                $i++;
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Đã cập nhật');
    }
    public function complete(Request $request)
    {
        $production_order_id = $request->production_order_id;
        ProductionOrderPriority::where('production_order_id', $production_order_id)->delete();
        ProductionOrderHistory::where('production_order_id', $production_order_id)->delete();
        ProductOrder::find($production_order_id)->update(['status' => 2]);
        return $this->success('', 'Hoàn thành thành công');
    }

    public function updateRecord(Request $request)
    {
        $input = $request->all();
        $new_order_quantity = $input['new_order_quantity'];
        $inventory_quantity = $input['inventory_quantity'];
        $fc_order_quantity = $input['fc_order_quantity'];
        $outstanding_order = $input['outstanding_order'];
        $production_quantity = ($new_order_quantity + $fc_order_quantity + $outstanding_order) - $inventory_quantity;
        if ($production_quantity < 0) {
            $production_quantity = 0;
        }
        $product_id = $input['product_id'];
        try {
            DB::beginTransaction();
            $inventory = Inventory::where('product_id', $product_id)->first();
            if ($inventory) {
                Inventory::where('product_id', $product_id)->update(['sl_ton' => $inventory_quantity]);
            } else {
                Inventory::create(['product_id' => $product_id, 'sl_ton' => $inventory_quantity]);
            }
            ProductionOrderPriority::where('product_id', $product_id)->update(['new_order_quantity' => $new_order_quantity, 'fc_order_quantity' => $fc_order_quantity, 'outstanding_order' => $outstanding_order, 'production_quantity' => $production_quantity]);
            $productionSteps = ProductionPlanController::getProductionSteps($product_id);
            $quantity = $production_quantity;
            foreach ($productionSteps as $productionStep) {
                $calculatedQuantity = ProductionPlanController::calculateProductionOutput($product_id, $productionStep->line_id, $quantity);
                $productOrderHistory = ProductionOrderHistory::where('product_id', $product_id)->where('line_id', $productionStep->line_id)->first();
                $order_quantity = $calculatedQuantity;
                $production_quantity = $calculatedQuantity - $productOrderHistory->inventory_quantity;
                if ($production_quantity < 0) {
                    $production_quantity = 0;
                }
                $quantity = $production_quantity;
                ProductionOrderHistory::where('product_id', $product_id)->where('line_id', $productionStep->line_id)->update(['order_quantity' => $order_quantity, 'production_quantity' => $production_quantity]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Cập nhật thành công');
    }
}
