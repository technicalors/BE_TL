<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Imports\ParametersImport;
use Maatwebsite\Excel\Facades\Excel;

class ParameterController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        $path = $request->file('file');

        try {
            Excel::import(new ParametersImport, $path);
            return response()->json(['message' => 'Nhập dữ liệu thành công'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Đã xảy ra lỗi. Vui lòng thử lại sau.', 'error' => $e->getMessage()], 500);
        }
    }
}
