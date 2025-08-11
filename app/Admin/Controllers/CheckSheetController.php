<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CheckSheet;
use App\Models\CheckSheetWork;
use App\Traits\API;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Hamcrest\Type\IsNumeric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckSheetController extends Controller
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
        try {
            DB::beginTransaction();
            $hang_muc = Checksheet::firstOrCreate(['name', $input['hang_muc']])->first();
            $input['checksheet_id'] = $hang_muc->id;
            $checksheet = CheckSheetWork::create([
                'line_id' => $input['line_id'],
                'machine_id' => $input['machine_id'],
                'checksheet_id' => $input['checksheet_id'],
                'cong_viec' => $input['cong_viec'],
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, 'Đã xảy ra lỗi');
        }
        return $this->success($checksheet, 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        try {
            DB::beginTransaction();
            $hang_muc = Checksheet::firstOrCreate(['name', $input['hang_muc']])->first();
            $input['checksheet_id'] = $hang_muc->id;
            $checksheet = CheckSheetWork::find($id);
            if(!$checksheet){
                return $this->failure($id, 'Không tìm thấy cheksheet');
            }
            $checksheet->update([
                'line_id' => $input['line_id'],
                'machine_id' => $input['machine_id'],
                'checksheet_id' => $input['checksheet_id'],
                'cong_viec' => $input['cong_viec'],
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, 'Đã xảy ra lỗi');
        }
        return $this->success($checksheet, 'Cập nhật thành công');
    }

    public function delete(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $checksheet = CheckSheetWork::find($id)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success($checksheet, 'Xoá thành công');
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
