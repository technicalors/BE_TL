<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Stamp;
use App\Traits\API;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StampController extends Controller
{
    use API;
    public function index()
    {   
        return $this->success(Stamp::all());
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
}
