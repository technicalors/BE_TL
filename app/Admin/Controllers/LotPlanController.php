<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\LotPlansImport;
use App\Models\Material;
use App\Models\LotPlan;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LotPlanController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = LotPlan::orderBy('lo_sx')->orderBy('lot_id')->orderBy('start_time');
        if (!empty($request->date)) {
            $start = date('Y-m-d', strtotime($request->date[0]));
            $end = date('Y-m-d', strtotime($request->date[1]));
            $query->whereDate('start_time', '>=', $start)->whereDate('start_time', '<=', $end);
        }
        if (!empty($request->lot_id)) {
            $query->where('lot_id', 'like', '%' . $request->lot_id . '%');
        }
        if (!empty($request->line_id)) {
            $query->whereIn('line_id', $request->line_id);
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $query->offset(($request->page - 1) * $request->pageSize)->limit($request->pageSize);
        }
        $result = $query->with('product', 'line', 'machine')->get();
        foreach ($result as $key => $value) {
            $value->thoi_gian_bat_dau = date('d/m/y H:i:s', strtotime($value->start_time));
            $value->thoi_gian_ket_thuc = date('d/m/y H:i:s', strtotime($value->end_time));
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function store(Request $request)
    {
        $stamp = LotPlan::create($request->all());
        return $this->success($stamp);
    }

    public function show($id)
    {
        $stamp = LotPlan::find($id);

        if (!$stamp) {
            return $this->success('', 'LotPlan not found');
        }

        return $this->success($stamp);
    }

    public function update(Request $request, $id)
    {
        $stamp = LotPlan::find($id);

        if (!$stamp) {
            return $this->failure('', 'LotPlan not found');
        }

        $request->validate([
            'lot_id' => 'required|string|max:255',
            'ten_sp' => 'required|string|max:255',
            'soluongtp' => 'required|integer',
            'ver' => 'nullable|string|max:50',
            'his' => 'nullable|string|max:50',
            'lsx' => 'required|string|max:255',
            'cd_thuc_hien' => 'nullable|string|max:255',
            'cd_tiep_theo' => 'nullable|string|max:255',
            'nguoi_sx' => 'nullable|string|max:255',
            'ghi_chu' => 'nullable|string',
        ]);

        $stamp->update($request->all());

        return $this->success($stamp);
    }

    public function destroy($id)
    {
        $stamp = LotPlan::find($id);

        if (!$stamp) {
            return $this->failure('', 'LotPlan not found');
        }

        $stamp->delete();

        return $this->success('', 'LotPlan deleted');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv',
        ]);

        try {
            Excel::import(new LotPlansImport, $request->file('file'));

            return $this->success('', 'Import successful');
        } catch (\Exception $e) {
            return $this->failure($e, 'Import failed');
        }
    }

    public function createTem()
    {
        $material = Material::where('id', 'VBD130')->get();
        return $this->failure($material, '');
    }
}
