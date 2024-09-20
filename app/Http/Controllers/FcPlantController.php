<?php

namespace App\Http\Controllers;

use App\Imports\FcPlantImport;
use App\Models\FcPlant;
use App\Models\FcPlantColumn;
use App\Models\MachineShift;
use App\Models\Shift;
use App\Models\ShiftBreak;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class FcPlantController extends Controller
{
    use API;

    public function index(Request $request)
    {
        $pageSize = $request->pageSize ?? 25;
        $records = FcPlant::with('details')->paginate($pageSize);
        $columns = FcPlantColumn::orderBy('id')->get(['id', 'name', 'value']);
        $result = [];
        foreach ($records->items() as $record) {
            $details = (object)[];
            foreach ($record->details as $detail) {
                $details->{$detail->col} = $detail->value;
            }
            $result[] = array_merge([
                'id' => $record->id,
                'code' => $record->code,
                'plant' => $record->plant,
                'plant_name' => $record->plant_name,
                'material' => $record->material,
                'model' => $record->model,
                'po' => $record->po,
                'sum_fc' => $record->sum_fc,
            ], (array) $details);
        }
        return $this->success([
            'data' => $result,
            'columns' => $columns,
            'paginate' => [
                'page' => $records->currentPage(),
                'page_size' => $pageSize,
                'total_items' => $records->total(),
                'total_pages' => $records->lastPage(),
            ]
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        DB::beginTransaction();
        try {
            Excel::import(new FcPlantImport, $request->file('file'));
            DB::commit();
            return $this->success([], 'UPLOAD THÀNH CÔNG', 201);
        } catch (Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }
}
