<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Machine;
use App\Models\Material;
use App\Models\MachinePriorityOrder;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class MachinePriorityOrderController extends Controller
{
    use API;
    public function index(Request $request)
    {   
        $query = MachinePriorityOrder::orderBy('line_id')->orderBy('priority');
        if(!empty($request->line_id)){
            $query->whereIn('line_id', $request->line_id ?? []);
        }
        if(!empty($request->line_id)){
            $query->whereIn('line_id', $request->line_id ?? []);
        }
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
        $result = $query->with('line', 'product')->get();
        foreach ($result as $key => $value) {
            $value->product_name = $value->product->name;
            $value->line_name = $value->line->name;
        }
        return $this->success(['data'=>$result, 'total'=>$total]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        $machine = Machine::where('code', $input['machine_id'])->first();
        $input['line_id'] = $machine->line_id ?? "";
        $validated = MachinePriorityOrder::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $machinePriorityOrder = MachinePriorityOrder::updateOrCreate(['product_id' => $input['product_id'], 'line_id' => $input['line_id'], 'machine_id' => $input['machine_id']], $input);
        $machinePriorityOrder->line_name = $machinePriorityOrder->line->name ?? "";
        return $this->success($machinePriorityOrder);
    }

    public function show($id)
    {
        $machinePriorityOrder = MachinePriorityOrder::find($id);

        if (!$machinePriorityOrder) {
            return $this->success('', 'MachinePriorityOrder not found');
        }

        return $this->success($machinePriorityOrder);
    }

    public function update(Request $request, $id)
    {
        $machinePriorityOrder = MachinePriorityOrder::find($id);
        if (!$machinePriorityOrder) {
            return $this->failure('', 'MachinePriorityOrder not found');
        }
        $input = $request->all();
        $machine = Machine::where('code', $input['machine_id'])->first();
        $input['line_id'] = $machine->line_id ?? "";
        $validated = MachinePriorityOrder::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $machinePriorityOrder->update($input);
        $machinePriorityOrder->line_name = $machinePriorityOrder->line->name ?? "";
        return $this->success($machinePriorityOrder);
    }

    public function destroy($id)
    {
        $machinePriorityOrder = MachinePriorityOrder::find($id);

        if (!$machinePriorityOrder) {
            return $this->failure('', 'MachinePriorityOrder not found');
        }

        $machinePriorityOrder->delete();

        return $this->success('','MachinePriorityOrder deleted');
    }

    public function deleteManyMachinePriorityOrders(Request $request){
        $machinePriorityOrder = MachinePriorityOrder::whereIn('id', $request->ids)->delete();
        return $this->success('','MachinePriorityOrder deleted'); 
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

    public function saveAll(Request $request){
        $input = $request->all();
        if(!isset($input['product_id'])){
            return $this->failure('', 'Không tìm thấy mã sản phẩm');
        }
        $data = [];
        foreach($input['data'] ?? [] as $value){
            $value['product_id'] = $input['product_id'];
            if(!isset($value['machine_id'])){
                return $this->failure('', 'Không tìm thấy mã máy');
            }
            $machine = Machine::where('code', $value['machine_id'])->first();
            $value['line_id'] = $machine->line_id ?? "";
            $validated = MachinePriorityOrder::validate($value);
            if ($validated->fails()) {
                return $this->failure('', $validated->errors()->first());
            }
            $data[] = $value;
        }
        foreach ($data as $key => $value) {
            MachinePriorityOrder::updateOrCreate(['product_id' => $value['product_id'], 'line_id' => $value['line_id'], 'machine_id' => $value['machine_id']], $value);
        }
        return $this->success($data);
    }
}
