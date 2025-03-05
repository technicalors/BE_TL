<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Material;
use App\Models\MachinePriorityOrder;
use App\Models\ProductionJourney;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ProductionJourneyController extends Controller
{
    use API;
    public function index(Request $request)
    {   
        $query = ProductionJourney::orderBy('line_id')->orderBy('product_id')->orderBy('production_order');
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
            $value->line_name = $value->line->name ?? "";
        }
        return $this->success(['data'=>$result, 'total'=>$total]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        $machinePriorityOrder = ProductionJourney::create($input);

        return $this->success($machinePriorityOrder);
    }

    public function show($id)
    {
        $machinePriorityOrder = ProductionJourney::find($id);

        if (!$machinePriorityOrder) {
            return $this->success('', 'ProductionJourney not found');
        }

        return $this->success($machinePriorityOrder);
    }

    public function update(Request $request, $id)
    {
        $machinePriorityOrder = ProductionJourney::find($id);
        if (!$machinePriorityOrder) {
            return $this->failure('', 'ProductionJourney not found');
        }
        $input = $request->all();
        $machinePriorityOrder->update($request->all());
        return $this->success($machinePriorityOrder);
    }

    public function destroy($id)
    {
        $machinePriorityOrder = ProductionJourney::find($id);

        if (!$machinePriorityOrder) {
            return $this->failure('', 'ProductionJourney not found');
        }

        $machinePriorityOrder->delete();

        return $this->success('','ProductionJourney deleted');
    }

    public function deleteManyMachinePriorityOrders(Request $request){
        $machinePriorityOrder = ProductionJourney::whereIn('id', $request->ids)->delete();
        return $this->success('','ProductionJourney deleted'); 
    }
}
