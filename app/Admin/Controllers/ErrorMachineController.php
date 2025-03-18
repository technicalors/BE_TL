<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\ErrorMachine;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErrorMachineController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ErrorMachine::orderBy('code');
        if(isset($request->code)){
            $query->where('code', 'like', "%$request->code%");
        }
        if(isset($request->noi_dung)){
            $query->where('noi_dung', 'like', "%$request->noi_dung%");
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $query->with('line');
        $result = $query->get();
        foreach ($result as $key => $value) {
            $value->type = ErrorMachine::ERROR_TYPE[$value->type];
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        $validated = ErrorMachine::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $error_machine = ErrorMachine::create($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $validated = ErrorMachine::validate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $error_machine = ErrorMachine::find($id)->update($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Cập nhật thành công');
    }

    public function destroy(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $bom = ErrorMachine::find($id)->delete();
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
            $bom = ErrorMachine::whereIn('id', $request)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Xoá thành công');
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
