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
            foreach ($input['dataHistory'] as $data) {
                ProductionOrderHistory::where('lo_sx', $input['lo_sx'])->where('line_id', $data['line_id'])->update(['produced_quantity' => $data['produced_quantity']]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Cập nhật thành công');
    }
}
