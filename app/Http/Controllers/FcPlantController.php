<?php

namespace App\Http\Controllers;

use App\Imports\FcPlantImport;
use App\Models\FcPlant;
use App\Models\FcPlantDetail;
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
        $start_date = Carbon::now()->subMonths(1)->format('Y-m-d');
        $end_date = Carbon::now()->format('Y-m-d');

        if (isset($request->start_date)) $start_date = $request->start_date;
        if (isset($request->end_date)) $end_date = $request->end_date;

        $pageSize = $request->pageSize ?? 25;
        $records = FcPlant::query()->whereHas('details', function ($q) use ($start_date, $end_date) {
            $q->whereDate('date', '>=', $start_date)->whereDate('date', '<=', $end_date);
        })->orderByDesc('created_at')->with('details')->paginate($pageSize);
        $columns = FcPlantDetail::query()->whereDate('date', '>=', $start_date)->whereDate('date', '<=', $end_date)->select(DB::raw("CONCAT(col, '(', DATE_FORMAT(date, '%d/%m'), ')') AS name"), DB::raw('col as value'))->groupBy('col', 'date')->get();

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
