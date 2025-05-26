<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\TemplateImport;
use App\Models\Template;
use App\Models\WarehouseHistories;
use App\Models\WarehouseInventory;
use App\Traits\API;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class TemplateController extends Controller
{
    use API;
    public function list(Request $request)
    {
        $query = Template::orderBy('created_at', 'DESC')->with('roll');
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->roll_id)) {
            $query->whereHas('roll', function ($q) use ($request) {
                $q->where('id', 'like', "%$request->roll_id%");
            });
        }
        if (isset($request->material_id)) {
            $query->where('material_id', 'like', "%$request->material_id%");
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $result = $query->get();
        foreach ($result as $key => $value) {
            $value->material_name = $value->material->name ?? "";
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        $validated = Template::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $result = Template::create($input);
            if($result){
                
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $validated = Template::validate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $result = Template::find($id);
            if($result){
                $result->update($input);
                $result->roll()->update(['material_id'=>$input['material_id'],'quantity'=>$input['quantity']]);
                WarehouseInventory::where('roll_id', $result->roll->id)->update(['material_id'=>$input['material_id'],'quantity'=>$input['quantity']]);
                WarehouseHistories::where('roll_id', $result->roll->id)->where('type', WarehouseHistories::TYPE_IMPORT)->update(['material_id'=>$input['material_id'],'quantity'=>$input['quantity']]);
            }
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
            $result = Template::find($id);
            if($result){
                WarehouseInventory::where('roll_id', $result->roll->id ?? null)->delete();
                WarehouseHistories::where('roll_id', $result->roll->id ?? null)->where('type', WarehouseHistories::TYPE_IMPORT)->delete();
                $result->roll()->delete();
                $result->delete();
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
        return $this->success('', 'Xoá thành công');
    }

    public function deleteMultiple(Request $request)
    {
        try {
            DB::beginTransaction();
            $result = Template::whereIn('id', $request)->delete();
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
        DB::beginTransaction();
        Template::query()->update(['status' => 1]);
        try {
            Excel::import(new TemplateImport, $request->file('file'));
            DB::commit();
            return $this->success('', 'Upload thành công');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            // Handle the exception and return an appropriate response
            return $this->failure(['error' => $e->getMessage()], $e->getMessage(), 422);
        }
    }
}
