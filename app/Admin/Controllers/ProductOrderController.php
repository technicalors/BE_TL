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
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->product_id)) {
            $query->where('product_id', 'like', "%$request->product_id%");
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        if (isset($request->withs)) {
            $query->with($request->withs);
        }
        $result = $query->with('product', 'customer', 'material', 'numberProductOrder')->get();
        foreach ($result as $value) {
            $spec = Spec::with('line')->where('product_id', $value->product_id)
                ->where('slug', 'hanh-trinh-san-xuat')
                ->orderBy('value', 'asc')
                ->groupBy('line_id')
                ->get()->filter(function ($value) {
                    return is_numeric($value->value);
                })->values();
            $sl_may = [];
            $numberProductOrder = $value->numberProductOrder;
            foreach ($spec as $key => $data) {
                $sl_may[$key]['name'] = $data->line->name;
                $sl_may[$key]['line_id'] = $data->line_id;
                $sl_may[$key]['value'] = $numberProductOrder->first(function ($item) use ($data) {
                    return $item->line_id == $data->line_id;
                })->number_machine ?? 0;
            }
            $ton = [];
            $san_luong = Lot::where('product_id', $value->product_id)->get()->groupBy('final_line_id');
            $sl_ton = 0;
            foreach ($san_luong as $line_id => $data) {
                $ton[$line_id]['name'] = '';
                $ton[$line_id]['line_id'] = $line_id;
                $sl = $data->sum('so_luong');
                $ton[$line_id]['value'] = $sl;
                $sl_ton += $sl;
            }
            $value->delivery_date = $value->delivery_date ? date('d/m/Y', strtotime($value->delivery_date)) : null;
            $value->sl_may = $sl_may;
            $value->ton = array_values($ton);
            $value->sl_ton = $sl_ton;
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
}
