<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\ProductOrderImport;
use App\Models\Line;
use App\Models\Lot;
use App\Models\NumberMachineOrder;
use App\Models\ProductOrder;
use App\Models\Spec;
use App\Traits\API;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProductOrderController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ProductOrder::orderBy('created_at', 'DESC');
        if (isset($request->start_date) && isset($request->end_date)) {
            $query->whereDate('order_date', '>=', date('Y-m-d', strtotime($request->start_date)))
                ->whereDate('order_date', '<=', date('Y-m-d', strtotime($request->end_date)));
        }
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->product_id)) {
            $query->where('product_id', 'like', "%$request->product_id%");
        }
        if (isset($request->khach_hang)) {
            $query->where('customer_id', 'like', "%$request->customer_id%");
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        if (isset($request->withs)) {
            $query->with($request->withs);
        }
        $result = $query->with('product', 'customer', 'material', 'numberProductOrder')->get();
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function show($id)
    {
        $stamp = ProductOrder::find($id);

        if (!$stamp) {
            return $this->success('', 'Product Order not found');
        }

        return $this->success($stamp);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        $validated = ProductOrder::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        DB::beginTransaction();
        try {
            Log::debug($input);
            $result = ProductOrder::create($input);
            $spec = Spec::with('line')->where('product_id', $input['product_id'])
                ->where('slug', 'hanh-trinh-san-xuat')
                ->orderBy('value', 'asc')
                ->groupBy('line_id')
                ->get()->filter(function ($value) {
                    return is_numeric($value->value);
                })->values();
            foreach ($spec as $key => $value) {
                NumberMachineOrder::create([
                    'product_order_id' => $result->id,
                    'line_id' => $value['line_id'],
                    'number_machine' => $value['value'],
                    'user_id' => $request->user()->id,
                ]);
            }
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
        $validated = ProductOrder::validate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $result = ProductOrder::find($id)->update($input);
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
            $result = ProductOrder::find($id)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Xoá thành công');
    }

    public function deleteMultiple(Request $request)
    {
        try {
            DB::beginTransaction();
            $result = ProductOrder::whereIn('id', $request)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Xoá thành công');
    }
    public function upload_xlsx($action, $title)
    {
        return view('import', [
            "action" => $action,
            "title" => $title
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        try {
            Excel::import(new ProductOrderImport($request->user()->id), $request->file('file'));
        } catch (\Exception $e) {
            // Handle the exception and return an appropriate response
            return $this->failure(['error' => $e->getMessage()], $e->getMessage(), 422);
        }
        return $this->success('', 'Upload thành công');
    }

    public function export(Request $request)
    {
        return $this->success('', 'Export thành công');
    }

    public function updateNumberMachine(Request $request)
    {
        try {
            DB::beginTransaction();
            $productOrder = ProductOrder::find($request->id);
            $productOrder->update($request->all());
            NumberMachineOrder::where('product_order_id', $request->id)->delete();
            foreach ($request->sl_may as $key => $value) {
                $line = Line::with('machine')->find($value['line_id']);
                if ($value['value'] > count($line->machine ?? [])) {
                    return $this->failure('', 'Số lượng máy của công đoạn "' . $line->name . '" vượt quá số lượng máy thực tế.');
                }
                NumberMachineOrder::create([
                    'product_order_id' => $productOrder->id,
                    'line_id' => $value['line_id'],
                    'number_machine' => $value['value'],
                    'user_id' => $request->user()->id,
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure('', $th->getMessage());
        }
        return $this->success('', 'Cập nhật thành công');
    }

    // public function getNumerMachine($productID,$initialQuantity, $time)
    // {
    //     $controller = new Phase2UIApiController();
    //     $productionSteps = $controller->getProductionSteps($productID);
    //     $stepQuantities = [];
    //     // Tính toán sản lượng cho từng công đoạn theo thứ tự DESC
    //     foreach ($productionSteps as $step) {
    //         $calculatedQuantity = $this->calculateProductionOutput($productID, $step->line_id, $initialQuantity);
    //         $stepQuantities[$step->line_id] = $calculatedQuantity;
    //         // Cập nhật lại initialQuantity cho công đoạn tiếp theo
    //         $initialQuantity = $calculatedQuantity;
    //     }
    //     $orderedSteps = $this->getOrderedProductionSteps($productID);
    //     foreach
    // }
}
