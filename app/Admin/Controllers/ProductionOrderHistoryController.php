<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrderHistory;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderHistoryController extends Controller
{
    use API;
    public function update(Request $request)
    {
        $input = $request->all();
        try {
            DB::beginTransaction();
            $productionSteps = Phase2UIApiController::getProductionSteps($input['product_id']);
            foreach ($productionSteps as $key => $value) {
                $result = collect($input['dataHistory'])->first(function ($item) use ($value) {
                    return $item['line_id'] == $value->line_id;
                });
                if (!isset($quantity)) {
                    $quantity = $result['order_quantity'];
                }
                $inventory_quantity = $result['inventory_quantity'];
                if ($quantity == 0) {
                    $calculatedQuantity = 0;
                } else {
                    $calculatedQuantity = Phase2UIApiController::calculateProductionOutput($input['product_id'], $result['line_id'], $quantity);
                }
                $order_quantity = $calculatedQuantity;
                $production_quantity = $calculatedQuantity - $inventory_quantity;
                if ($production_quantity < 0) {
                    $production_quantity = 0;
                }
                $quantity = $production_quantity < 0 ? 0 : $production_quantity;
                ProductionOrderHistory::where('product_id', $input['product_id'])->where('line_id', $result['line_id'])->update(['order_quantity' => $order_quantity, 'production_quantity' => $production_quantity, 'inventory_quantity' => $inventory_quantity]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Cập nhật thành công');
    }
}
