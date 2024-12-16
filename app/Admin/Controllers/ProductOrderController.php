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

class ProductOrderController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ProductOrder::where('quantity', '>', 0)->orderBy('created_at', 'DESC');
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
        $lines = Line::where("display", "1")
        ->where('factory_id', 2)
        ->orderBy('ordering', 'ASC')
        ->get();
        $except = ['kho-thanh-pham', 'oqc', 'iqc', 'kho-thanh-pham', 'kho-bao-on', 'u'];
        foreach ($result as $value) {
            $spec = Spec::with('line')
                ->whereIn('id', function ($query) use ($value) {
                    $query->selectRaw('MIN(id)')
                        ->from('spec')
                        ->where('product_id', $value->product_id)
                        ->where('slug', 'hanh-trinh-san-xuat')
                        ->where('line_id', '<>', 24)
                        ->groupBy('line_id');
                })
                ->orderBy('value', 'asc')
                ->get();
            $sl_may = [];
            $numberProductOrder = $value->numberProductOrder;
            foreach ($lines as $key => $line) {
                if(in_array(Str::slug($line->name), $except)) continue;
                $sl_may[$key]['name'] = $line->name;
                $sl_may[$key]['line_id'] = $line->id;
                $sl_may[$key]['value'] = $numberProductOrder->first(function ($item) use ($line) {
                    return $item->line_id == $line->id;
                })->number_machine ?? 0;
            }
            $ton = [];
            $san_luong = LineInventories::with('line')->where('product_id', $value->product_id)->get()->groupBy('line_id');
            $sl_ton = 0;
            foreach ($lines as $key => $line) {
                if(in_array(Str::slug($line->name), $except)) continue;
                $ton[$key]['name'] = $line->name ?? '';
                $ton[$key]['line_id'] = $line->id;
                $sl = isset($san_luong[$line->id]) ? $san_luong[$line->id]->sum('quantity') : 0;
                $ton[$key]['value'] = $sl;
                $sl_ton += $sl;
            }
            $value->order_date = $value->order_date ? date('d/m/Y', strtotime($value->order_date)) : null;
            $value->delivery_date = $value->delivery_date ? date('d/m/Y', strtotime($value->delivery_date)) : null;
            $value->sl_may = $sl_may;
            $value->ton = array_values($ton);
            $inventory = Inventory::where('product_id', $value->product_id)->first();
            $value->sl_ton = $inventory->sl_ton ?? 0;
        }
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

    public function updateNumberMachine(Request $request)
    {
        try {
            DB::beginTransaction();
            $productOrder = ProductOrder::find($request->id);
            $productOrder->update($request->all());
            NumberMachineOrder::where('product_order_id', $request->id)->delete();
            foreach ($request->sl_may as $key => $value) {
                $numberMachine = MachinePriorityOrder::where('product_id', $productOrder->product_id)
                    ->where('line_id', $value['line_id'])
                    ->count();
                if ($numberMachine > 0 && $value['value'] > $numberMachine) {
                    return $this->failure('', 'Số lượng máy của công đoạn "' . $value['name'] . '" không được vượt quá ' . $numberMachine . ' máy.');
                }
                NumberMachineOrder::create([
                    'product_order_id' => $productOrder->id,
                    'line_id' => $value['line_id'],
                    'number_machine' => $value['value'],
                    'user_id' => $request->user()->id,
                ]);
            }
            foreach ($request->ton as $key => $value) {
                LineInventories::where('line_id', $value['line_id'])->where('product_id', $productOrder->product_id)->update(['quantity'=>$value['value']]);
            }
            if(isset($request->sl_ton)){
                Inventory::where('product_id', $request->product_id)->update(['sl_ton'=>$request->sl_ton]);
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

    public function export()
    {
        # Set file path
        $timestamp = date('YmdHi');
        $file = "DonHang_$timestamp.xlsx";
        $filePath = "export/$file";
        $result = Excel::store(new ProductOrderExport(), $filePath, 'excel');

        if (empty($result)) return $this->failure([], 'THAO TÁC THẤT BẠI', 500);
        # Generate file base64
        $fileContent = Storage::disk('excel')->get($filePath);
        $fileType = File::mimeType(storage_path("app/excel/$filePath"));
        $base64 = base64_encode($fileContent);
        $fileBase64Uri = "data:$fileType;base64,$base64";

        # Delete if needed
        Storage::disk('excel')->delete($filePath);

        # Return
        return $this->success([
            'file' => $file,
            'type' => $fileType,
            'data' => $fileBase64Uri,
        ]);
    }
}
