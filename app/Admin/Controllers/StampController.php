<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\InfoCongDoan;
use App\Models\LotPlan;
use App\Models\Material;
use App\Models\Stamp;
use App\Traits\API;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StampController extends Controller
{
    use API;
    public function index(Request $request)
    {   
        $query = Stamp::orderBy('lot_id');
        if(!empty($request->lot_id)){
            $query->where('lot_id', 'like', '%' . $request->lot_id . '%');
        }
        $result = $query->get();
        return $this->success($result);
    }

    public function store(Request $request)
    {
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

        $stamp = Stamp::create($request->all());

        return $this->success($stamp);
    }

    public function show($id)
    {
        $stamp = Stamp::find($id);

        if (!$stamp) {
            return $this->success('', 'Stamp not found');
        }

        return $this->success($stamp);
    }

    public function update(Request $request, $id)
    {
        $stamp = Stamp::find($id);

        if (!$stamp) {
            return $this->failure('', 'Stamp not found');
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
        $stamp = Stamp::find($id);

        if (!$stamp) {
            return $this->failure('', 'Stamp not found');
        }

        $stamp->delete();

        return $this->success('','Stamp deleted');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv',
        ]);

        try {
            Excel::import(new StampsImport, $request->file('file'));

            return $this->success('', 'Import successful');
        } catch (\Exception $e) {
            return $this->failure($e, 'Import failed');
        }
    }

    public function createTem(){
        // $material = Material::whereIn('id', ['C01', 'C16'])->get();
        $tem = LotPlan::with('product')->whereIn('lot_id', ['2410007.L.0001', '2410004.L.0022', '2410052.L.0001'])->where('line_id', 24)->get();
        foreach ($tem as $key => $value) {
            $value->soluongtp = $value->so_luong ?? 10000;
            $value->lsx = $value->lo_sx;
            $value->ver = $value->product->ver ?? "";
            $value->his = $value->product->his ?? "";
            $value->cd_thuc_hien = "Gấp dán liên hoàn";
            $value->cd_tiep_theo = "In flexo";
            $value->ten_sp = $value->product->name ?? "";
            $value->ngay_sx = date('d/m/Y');
            $value->tg_sx = date('d/m/Y H:i:s');
        }
        return $this->failure($tem, '');
    }
}
