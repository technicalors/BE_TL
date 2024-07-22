<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\ErrorMachineImport;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ErrorMachineApiController extends Controller
{
    use API;

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        DB::beginTransaction();
        try {
            Excel::import(new ErrorMachineImport, $request->file('file'));
            DB::commit();
            return $this->success('', 'Upload thành công');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->failure(['error' => $e->getMessage()], 'Upload thất bại', 422);
        }
    }
}
