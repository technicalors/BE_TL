<?php

namespace App\Admin\Controllers;

use App\Exports\Production\ProductOrderExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductOrderImport;
use App\Models\Bom;
use App\Models\Inventory;
use App\Models\Line;
use App\Models\LineInventories;
use App\Models\Losx;
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

class ProductOrderController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ProductOrder::where('quantity', '>', 0)
            ->orderBy('product_id')
            ->orderBy('created_at', 'DESC');
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
        $result = $query->with('product.materials.warehouse_inventories', 'customer', 'material', 'numberProductOrder')->get();
        $lines = Line::where("display", "1")
            ->where('factory_id', 2)
            ->where('id', '!=', 29)
            ->orderBy('ordering', 'ASC')
            ->get();
        $except = ['kho-thanh-pham', 'oqc', 'iqc', 'kho-thanh-pham', 'kho-bao-on', 'u'];
        foreach ($result as $value) {
            if (!$value->product) {
                continue;
            }
            $sl_may = [];
            $numberProductOrder = $value->numberProductOrder;
            foreach ($lines as $key => $line) {
                if (in_array(Str::slug($line->name), $except)) continue;
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
                if (in_array(Str::slug($line->name), $except)) continue;
                $ton[$key]['name'] = $line->name ?? '';
                $ton[$key]['line_id'] = $line->id;
                $sl = isset($san_luong[$line->id]) ? $san_luong[$line->id]->sum('quantity') : 0;
                $ton[$key]['value'] = $sl;
                $sl_ton += $sl;
            }
            $value->order_date = $value->order_date ? date('d-m-Y', strtotime($value->order_date)) : null;
            $value->sl_may = $sl_may;
            $value->ton = array_values($ton);
            $inventory = Inventory::where('product_id', $value->product_id)->first();
            $value->sl_ton = $inventory->sl_ton ?? 0;
            $value->ton_kho_nvl = $value->product->materials->sum(function ($material) {
                return $material->warehouse_inventories->sum('quantity') ?? 0;
            });
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
            foreach (($request->sl_may ?? []) as $key => $value) {
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
            foreach (($request->ton ?? []) as $key => $value) {
                LineInventories::updateOrCreate(['line_id' => $value['line_id'], 'product_id' => $productOrder->product_id], ['quantity' => $value['value']]);
            }
            if (isset($request->sl_ton)) {
                Inventory::updateOrCreate(['product_id' => $productOrder->product_id], ['sl_ton' => $request->sl_ton]);
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

    public function updateConfirmDate(Request $request)
    {
        try {
            DB::beginTransaction();
            $input = $request->all();
            $productOrder = ProductOrder::find($input['id']);
            $input['status'] = ProductOrder::STATUS_IN_PROGRESS;
            if ($input['type'] == 1) {
                Losx::where('product_id', $productOrder->product_id)->update(['status' => 2]);

                $losx['id'] = Losx::generateUniqueId($productOrder->product_id);
                $losx['product_order_id'] = $input['id'];
                $losx['product_id'] = $productOrder->product_id;
                $losx['order_quantity'] = $productOrder->quantity;
                $losx['priority'] = 1;
                $losx['status'] = 1;
                $losx['delivery_date'] = $input['confirm_date'];
                $record = Losx::create($losx);

                $productionSteps = ProductionPlanController::getProductionSteps($productOrder->product_id);
                $quantity = $productOrder->quantity;
                foreach ($productionSteps as $productionStep) {
                    if ($quantity > 0) {
                        $calculatedQuantity = ProductionPlanController::calculateProductionOutput($productOrder->product_id, $productionStep->line_id, $quantity);
                        $quantity = $calculatedQuantity;
                    }
                    if ($productionStep->line_id == 24) {
                        $bom = Bom::where('product_id', $productOrder->product_id)->where('priority', 1)->first();
                        $component_id = $bom->material_id;;
                    } else {
                        $component_id = $productOrder->product_id;
                    }
                    $inp['lo_sx'] = $record->id;
                    $inp['product_id'] = $productOrder->product_id;
                    $inp['component_id'] = $component_id;
                    $inp['line_id'] = $productionStep->line_id;
                    $inp['order_quantity'] = $quantity;
                    $inp['produced_quantity'] = 0;
                    $inp['inventory_quantity'] = 0;
                    ProductionOrderHistory::create($inp);
                }
            } elseif ($input['type'] == 2) {
                $losx = Losx::where('product_id', $productOrder->product_id)->orderBy('created_at', 'DESC')->first();
                $losx->update(['delivery_date' => $input['confirm_date'], 'order_quantity' => $productOrder->quantity + $losx->order_quantity]);
                $productionSteps = ProductionPlanController::getProductionSteps($productOrder->product_id);
                $quantity = $productOrder->quantity;
                foreach ($productionSteps as $productionStep) {
                    if ($quantity > 0) {
                        $calculatedQuantity = ProductionPlanController::calculateProductionOutput($productOrder->product_id, $productionStep->line_id, $quantity);
                        $quantity = $calculatedQuantity;
                    }
                    $productOrderHistory = ProductionOrderHistory::where('lo_sx', $losx->id)->where('line_id', $productionStep->line_id)->first();
                    ProductionOrderHistory::where('lo_sx', $losx->id)->where('line_id', $productionStep->line_id)->update(['order_quantity' => $quantity + $productOrderHistory->order_quantity]);
                }
            }
            // $new_order_quantity = $productOrder->quantity;
            // $fc_order_quantity = $productOrder->fc_quantity;
            // $inventory = Inventory::where('product_id', $productOrder->product_id)->first();
            // $productionOrderPriority = ProductionOrderPriority::where('product_id', $productOrder->product_id)->first();
            // $productionSteps = ProductionPlanController::getProductionSteps($productOrder->product_id);
            // if (!$productionOrderPriority) {
            //     $maxPriority = ProductionOrderPriority::max('priority');
            //     $newPriority = ($maxPriority !== null) ? $maxPriority + 1 : 1;
            //     $production_quantity = ($new_order_quantity + $fc_order_quantity) - ($inventory->sl_ton ?? 0);
            //     if ($production_quantity < 0) {
            //         $production_quantity = 0;
            //     }
            //     ProductionOrderPriority::firstOrCreate(
            //         ['product_id' => $productOrder->product_id],
            //         [
            //             'production_order_id' => $input['id'],
            //             'confirm_date'        => $input['confirm_date'],
            //             'product_id'          => $productOrder->product_id,
            //             'priority'            => $newPriority,
            //             'new_order_quantity'  => $new_order_quantity,
            //             'fc_order_quantity'   => $fc_order_quantity,
            //             'outstanding_order'   => 0,
            //             'production_quantity' => $production_quantity
            //         ]
            //     );

            //     $quantity = $production_quantity;
            //     foreach ($productionSteps as $productionStep) {
            //         if ($quantity > 0) {
            //             $calculatedQuantity = ProductionPlanController::calculateProductionOutput($productOrder->product_id, $productionStep->line_id, $quantity);
            //             $quantity = $calculatedQuantity;
            //         }
            //         if ($productionStep->line_id == 24) {
            //             $bom = Bom::where('product_id', $productOrder->product_id)->where('priority', 1)->first();
            //             $component_id = $bom->material_id;;
            //         } else {
            //             $component_id = $productOrder->product_id;
            //         }
            //         $inp['product_id'] = $productOrder->product_id;
            //         $inp['component_id'] = $component_id;
            //         $inp['line_id'] = $productionStep->line_id;
            //         $inp['order_quantity'] = $quantity;
            //         $inp['production_quantity'] = $quantity;
            //         $inp['inventory_quantity'] = 0;
            //         ProductionOrderHistory::create($inp);
            //     }
            // } else {
            //     $outstanding_order = $productionOrderPriority->outstanding_order + $productionOrderPriority->new_order_quantity;
            //     $production_quantity = ($new_order_quantity + $fc_order_quantity + $outstanding_order) - ($inventory->sl_ton ?? 0);
            //     if ($production_quantity < 0) {
            //         $production_quantity = 0;
            //     }
            //     $productionOrderPriority->update([
            //         'confirm_date'        => $input['confirm_date'],
            //         'new_order_quantity'  => $new_order_quantity,
            //         'fc_order_quantity'   => $fc_order_quantity,
            //         'production_quantity' => $production_quantity,
            //         'outstanding_order'   => $outstanding_order
            //     ]);

            //     $quantity = $production_quantity;
            //     foreach ($productionSteps as $productionStep) {
            //         if ($quantity > 0) {
            //             $calculatedQuantity = Phase2UIApiController::calculateProductionOutput($productOrder->product_id, $productionStep->line_id, $quantity);
            //             $quantity = $calculatedQuantity;
            //         }
            //         $productOrderHistory = ProductionOrderHistory::where('product_id', $productOrder->product_id)->where('line_id', $productionStep->line_id)->first();
            //         $order_quantity = $quantity;
            //         $production_quantity = $quantity - $productOrderHistory->inventory_quantity;
            //         if ($production_quantity < 0) {
            //             $production_quantity = 0;
            //         }
            //         $quantity = $production_quantity;
            //         ProductionOrderHistory::where('product_id', $productOrder->product_id)->where('line_id', $productionStep->line_id)->update(['order_quantity' => $order_quantity, 'production_quantity' => $production_quantity]);
            //     }
            // }

            $records = Losx::where('status', 1)->orderBy(DB::raw('DATE(delivery_date)'), 'asc')
                ->orderBy('product_id', 'asc')
                ->get();

            $priority = 1;
            foreach ($records as $record) {
                $record->update(['priority' => $priority]);
                $priority++;
            }
            $productOrder->update($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure('', $th->getMessage());
        }
        return $this->success('', 'Cập nhật thành công');
    }
    public function getProductOrderPriority(Request $request)
    {
        $query = ProductionOrderPriority::orderBy('priority', 'ASC');
        if (isset($request->product_id)) {
            $query->where('product_id', $request->product_id);
        }
        $result = $query->with('productionOrder.product', 'productionOrder.customer', 'productionOrderHistory')->get();
        return $this->success($result);
    }
}
