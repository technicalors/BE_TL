<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\MaterialImport;
use App\Models\Material;
use App\Traits\API;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class MaterialController extends Controller
{
    use API;
    public function list(Request $request)
    {
        $query = Material::orderBy('created_at', 'DESC');
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->name)) {
            $query->where('name', 'like', "%$request->name%");
        }
        if (isset($request->product_id)) {
            $query->whereHas('products', function($q) use($request) {
                $q->where('products.id', $request->product_id);
            });
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $result = $query->get();
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        $validated = Material::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $material = Material::create($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $validated = Material::validate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $material = Material::find($id)->update($input);
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
            $material = Material::find($id)->delete();
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
            $material = Material::whereIn('id', $request)->delete();
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
            'files' => 'required|mimes:xlsx',
        ]);
        try {
            Excel::import(new MaterialImport, $request->file('files'));
        } catch (\Exception $e) {
            // Handle the exception and return an appropriate response
            return $this->failure(['error' => $e->getMessage()], 'File import failed', 422);
        }
        return $this->success('', 'Upload thành công');
    }

    public function export(Request $request)
    {
        return $this->success('', 'Export thành công');
    }
}
