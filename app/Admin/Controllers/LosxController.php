<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Losx;
use App\Models\ProductionOrderHistory;
use App\Traits\API;

use Illuminate\Http\Request;


class LosxController extends Controller
{
    use API;
    public function getPriorities(Request $request)
    {
        $query = Losx::with('productionOrderHistory.line')->where('status', '<>', 2)->orderBy('status','ASC')->orderBy('priority', 'ASC');
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $query->offset(($request->page - 1) * $request->pageSize)->limit($request->pageSize);
        }
        $result = $query->with('product')->get();
        foreach ($result as $key => $losx) {
            $losx->produced_quantity = ($losx->productionOrderHistory && count($losx->productionOrderHistory) > 0) ? $losx->productionOrderHistory[0]->produced_quantity : 0;
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function update(Request $request)
    {
        $input = $request->all();
        $losx = Losx::find($input['id']);
        $losx->order_quantity = $input['order_quantity'];
        $losx->save();

        $productionSteps = ProductionPlanController::getProductionSteps($losx->product_id);
        $quantity = $input['order_quantity'];
        foreach ($productionSteps as $key => $productionStep) {
            if ($quantity > 0 && $key > 0) {
                $calculatedQuantity = ProductionPlanController::calculateProductionOutput($losx->product_id, $productionStep->line_id, $quantity);
                $quantity = $calculatedQuantity;
            }
            ProductionOrderHistory::where('lo_sx', $losx->id)->where('line_id', $productionStep->line_id)->update(['order_quantity' => $quantity]);
        }
        return $this->success('', 'Cập nhật thành công');
    }
    public function updateStatus(Request $request)
    {
        $input = $request->all();
        $losx = Losx::find($input['id']);
        $losx->status = $input['status'];
        $losx->save();
        return $this->success('', 'Cập nhật thành công');
    }
}
