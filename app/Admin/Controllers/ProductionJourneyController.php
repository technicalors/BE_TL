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
        $validated = ProductionJourney::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $productionJourney = ProductionJourney::updateOrCreate(['product_id' => $input['product_id'], 'line_id' => $input['line_id']], $input);
        $productionJourney->line_name = $productionJourney->line->name ?? "";
        return $this->success($productionJourney);
    }

    public function show($id)
    {
        $productionJourney = ProductionJourney::find($id);

        if (!$productionJourney) {
            return $this->success('', 'ProductionJourney not found');
        }

        return $this->success($productionJourney);
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $productionJourney = ProductionJourney::find($id);
        if (!$productionJourney) {
            return $this->failure('', 'ProductionJourney not found');
        }
        $validated = ProductionJourney::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $input = $request->all();
        $productionJourney->update($input);
        $productionJourney->line_name = $productionJourney->line->name ?? "";
        return $this->success($productionJourney);
    }

    public function destroy($id)
    {
        $productionJourney = ProductionJourney::find($id);

        if (!$productionJourney) {
            return $this->failure('', 'ProductionJourney not found');
        }

        $productionJourney->delete();

        return $this->success('','ProductionJourney deleted');
    }

    public function saveAll(Request $request){
        $input = $request->all();
        if(!isset($input['product_id'])){
            return $this->failure('', 'Không tìm thấy mã sản phẩm');
        }
        $data = [];
        foreach($input['data'] ?? [] as $value){
            $value['product_id'] = $input['product_id'];
            $validated = ProductionJourney::validate($value);
            if ($validated->fails()) {
                return $this->failure('', $validated->errors()->first());
            }
            $data[] = $value;
        }
        foreach ($data as $key => $value) {
            ProductionJourney::updateOrCreate(['product_id' => $value['product_id'], 'line_id' => $value['line_id']], $value);
        }
        return $this->success($data);
    }
}
