<?php

namespace App\Admin\Controllers;

use App\Models\CheckSheet;
use App\Models\CheckSheetWork;
use App\Traits\API;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Hamcrest\Type\IsNumeric;
use Illuminate\Http\Request;

class CheckSheetController extends AdminController
{
    use API;
    public function list(Request $request)
    {
        $query = CheckSheet::orderBy('created_at', 'DESC');
        if (isset($request->product_id)) {
            $query->where('product_id', 'like', "%$request->product_id%");
        }
        if (isset($request->product_name)) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', "%$request->product_name%");
            });
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $query->with('product');
        $result = $query->get();
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        // $validated = SelectionLineStampTemplate::validate($input);
        // if ($validated->fails()) {
        //     return $this->failure('', $validated->errors()->first());
        // }
        $product = Product::find($input['product_id']);
        if(!$product){
            return $this->success($input, 'Không tìm thấy sản phẩm');
        }
        try {
            DB::beginTransaction();
            $input['part_no'] = $product->name;
            $input['box_quantity'] = str_pad($input['box_quantity'], 6, '0', STR_PAD_LEFT);
            $input['po_type'] = 'E1';
            $selectionLineStampTemplate = SelectionLineStampTemplate::create($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, 'Đã xảy ra lỗi');
        }
        $selectionLineStampTemplate->material_name = $bom->material->name ?? "";
        return $this->success($selectionLineStampTemplate, 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        // $validated = SelectionLineStampTemplate::validate($input, $id);
        // if ($validated->fails()) {
        //     return $this->failure('', $validated->errors()->first());
        // }
        $product = Product::find($input['product_id']);
        if(!$product){
            return $this->success($input, 'Không tìm thấy sản phẩm');
        }
        try {
            DB::beginTransaction();
            $input['part_no'] = $product->name;
            $input['box_quantity'] = str_pad($input['box_quantity'], 6, '0', STR_PAD_LEFT);
            $input['po_type'] = 'E1';
            $selectionLineStampTemplate = SelectionLineStampTemplate::find($id);
            if($selectionLineStampTemplate){
                $selectionLineStampTemplate->update($input);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, 'Đã xảy ra lỗi');
        }
        return $this->success($selectionLineStampTemplate, 'Cập nhật thành công');
    }

    public function delete(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            SelectionLineStampTemplate::find($id)->delete();
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
            SelectionLineStampTemplate::whereIn('id', $request)->delete();
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
        // try {
        //     Excel::import(new MoldsImport, $request->file('file'));
        // } catch (\Exception $e) {
        //     // Handle the exception and return an appropriate response
        //     return $this->failure(['error' => $e->getMessage()], 'File import failed', 422);
        // }
        return $this->success('', 'Upload thành công');
    }

    public function export(Request $request)
    {
        return $this->success('', 'Export thành công');
    }
}
