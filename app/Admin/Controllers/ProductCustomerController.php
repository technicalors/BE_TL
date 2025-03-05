<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Material;
use App\Models\ProductCustomer;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ProductCustomerController extends Controller
{
    use API;
    public function index(Request $request)
    {   
        $query = ProductCustomer::orderBy('product_id');
        if(!empty($request->customer_id)){
            $query->where('customer_id', $request->customer_id);
        }
        if(!empty($request->product_id)){
            $query->where('product_id', $request->product_id);
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
        $result = $query->with('customer', 'product')->get();
        foreach ($result as $key => $value) {
            $value->product_name = $value->product->name ?? "";
            $value->customer_name = $value->customer->name ?? "";
        }
        return $this->success(['data'=>$result, 'total'=>$total]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        $validated = ProductCustomer::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $productCustomer = ProductCustomer::updateOrCreate($input);
        $productCustomer->customer_name = $productCustomer->customer->name ?? "";
        return $this->success($productCustomer);
    }

    public function show($id)
    {
        $productCustomer = ProductCustomer::find($id);

        if (!$productCustomer) {
            return $this->success('', 'productCustomer not found');
        }

        return $this->success($productCustomer);
    }

    public function update(Request $request, $id)
    {
        $productCustomer = ProductCustomer::find($id);
        if (!$productCustomer) {
            return $this->failure('', 'productCustomer not found');
        }
        $input = $request->all();
        $validated = ProductCustomer::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $productCustomer->update($request->all());
        $productCustomer->customer_name = $productCustomer->customer->name ?? "";
        return $this->success($productCustomer);
    }

    public function destroy($id)
    {
        $productCustomer = ProductCustomer::find($id);

        if (!$productCustomer) {
            return $this->failure('', 'productCustomer not found');
        }

        $productCustomer->delete();

        return $this->success('','productCustomer deleted');
    }

    public function deleteManyProductCustomer(Request $request){
        $productCustomer = ProductCustomer::whereIn('id', $request->ids)->delete();
        return $this->success('','productCustomer deleted'); 
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
            $validated = ProductCustomer::validate($value);
            if ($validated->fails()) {
                return $this->failure('', $validated->errors()->first());
            }
            $data[] = $value;
        }
        foreach ($data as $key => $value) {
            ProductCustomer::updateOrCreate($value);
        }
        return $this->success($data);
    }
}
