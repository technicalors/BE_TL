<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    use API;
    public function list(Request $request)
    {
        $query = Bom::orderBy('created_at', 'DESC');
        if (isset($request->product_id)) {
            $query->where('product_id', 'like', "%$request->product_id%");
        }
        if (isset($request->product_name)) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', "%$request->product_name%");
            });
        }
        if (isset($request->material_id)) {
            $query->where('material_id', 'like', "%$request->material_id%");
        }
        if (isset($request->material_name)) {
            $query->whereHas('material', function ($q) use ($request) {
                $q->where('name', 'like', "%$request->material_name%");
            });
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $query->with('material', 'product');
        $result = $query->get();
        foreach ($result as $key => $value) {
            $value->material_name = $value->material->name ?? "";
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        $validated = Bom::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $bom = Bom::create($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success($bom, 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $validated = Bom::validate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $bom = Bom::find($id)->update($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success($bom, 'Cập nhật thành công');
    }

    public function delete(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $bom = Bom::find($id)->delete();
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
            $bom = Bom::whereIn('id', $request)->delete();
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
