<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Material;
use App\Models\qcCriteria;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class QCCriteriaController extends Controller
{
    use API;
    public function index(Request $request)
    {   
        $query = QCCriteria::orderBy('product_id')->orderBy('line_id');
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
        // $validated = QCCriteria::validate($input);
        // if ($validated->fails()) {
        //     return $this->failure('', $validated->errors()->first());
        // }
        $qcCriteria = QCCriteria::create($input);

        return $this->success($qcCriteria);
    }

    public function show($id)
    {
        $qcCriteria = QCCriteria::find($id);

        if (!$qcCriteria) {
            return $this->success('', 'qcCriteria not found');
        }

        return $this->success($qcCriteria);
    }

    public function update(Request $request, $id)
    {
        $qcCriteria = QCCriteria::find($id);
        if (!$qcCriteria) {
            return $this->failure('', 'qcCriteria not found');
        }
        $input = $request->all();
        // $validated = QCCriteria::validate($input);
        // if ($validated->fails()) {
        //     return $this->failure('', $validated->errors()->first());
        // }
        $qcCriteria->update($request->all());
        return $this->success($qcCriteria);
    }

    public function destroy($id)
    {
        $qcCriteria = QCCriteria::find($id);

        if (!$qcCriteria) {
            return $this->failure('', 'qcCriteria not found');
        }

        $qcCriteria->delete();

        return $this->success('','qcCriteria deleted');
    }

    public function deleteManyQCCriteria(Request $request){
        $qcCriteria = QCCriteria::whereIn('id', $request->ids)->delete();
        return $this->success('','qcCriteria deleted'); 
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
