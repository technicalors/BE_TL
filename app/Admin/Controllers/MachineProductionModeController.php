<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Material;
use App\Models\machineProductionMode;
use App\Models\ProductionJourney;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class MachineProductionModeController extends Controller
{
    use API;
    public function index(Request $request)
    {   
        $query = MachineProductionMode::orderBy('product_id')->orderBy('machine_id');
        if(!empty($request->product_id)){
            $query->where('product_id', 'like', '%' . $request->product_id . '%');
        }
        if(!empty($request->product_name)){
            $query->whereHas('product', function($q)use($request){
                $q->where('name', 'like', "%$request->product_name%");
            });
        }
        $total = $query->count();
        if(!empty($request->page) && !empty($request->pageSize)){
            $page = ($request->page - 1) ?? 0;
            $pageSize = $request->pageSize;
            $query->offset($page * $pageSize)->limit($pageSize);
        }
        $result = $query->with('machine', 'product')->get();
        foreach ($result as $key => $value) {
            $value->product_name = $value->product->name;
            $value->machine_name = $value->machine->name;
        }
        return $this->success(['data'=>$result, 'total'=>$total]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        // $validated = machineProductionMode::validate($input);
        // if ($validated->fails()) {
        //     return $this->failure('', $validated->errors()->first());
        // }
        $machineProductionMode = MachineProductionMode::create($input);

        return $this->success($machineProductionMode);
    }

    public function show($id)
    {
        $machineProductionMode = MachineProductionMode::find($id);

        if (!$machineProductionMode) {
            return $this->success('', 'MachineProductionMode not found');
        }

        return $this->success($machineProductionMode);
    }

    public function update(Request $request, $id)
    {
        $machineProductionMode = MachineProductionMode::find($id);
        if (!$machineProductionMode) {
            return $this->failure('', 'MachineProductionMode not found');
        }
        // $input = $request->all();
        // $validated = machineProductionMode::validate($input);
        // if ($validated->fails()) {
        //     return $this->failure('', $validated->errors()->first());
        // }
        $machineProductionMode->update($request->all());
        return $this->success($machineProductionMode);
    }

    public function destroy($id)
    {
        $machineProductionMode = MachineProductionMode::find($id);

        if (!$machineProductionMode) {
            return $this->failure('', 'MachineProductionMode not found');
        }

        $machineProductionMode->delete();

        return $this->success('','MachineProductionMode deleted');
    }

    public function deleteManymachineProductionModes(Request $request){
        $machineProductionMode = MachineProductionMode::whereIn('id', $request->ids)->delete();
        return $this->success('','MachineProductionMode deleted'); 
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
