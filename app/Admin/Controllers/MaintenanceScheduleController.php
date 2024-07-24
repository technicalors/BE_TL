<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\MaintenanceScheduleImport;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceItem;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceLogImage;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceSchedule;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class MaintenanceScheduleController extends Controller
{
    use API;
    public function index()
    {
        $data = MaintenanceSchedule::all();
        return $this->success($data);
    }

    public function show($id)
    {
        return MaintenanceSchedule::find($id);
    }

    public function store(Request $request)
    {
        $maintenanceSchedule = MaintenanceSchedule::create($request->all());
        return response()->json($maintenanceSchedule, 201);
    }

    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $maintenanceSchedule = MaintenanceSchedule::findOrFail($id);
            $maintenanceSchedule->update($request->all());

            $maintenanceLog = MaintenanceLog::where(['maintenance_schedule_id' => $id])->first();
            if ($maintenanceLog) {
                $newMaintenanceLog = MaintenanceLog::create([
                    'maintenance_schedule_id' => $id,
                    'log_date' => $request->get('start_date') ?? now(),
                    'remark' => $request->get('remark') ?? '',
                    'result' => $request->get('result') ?? ''
                ]);
                $logImages = MaintenanceLogImage::where('maintenance_log_id', $maintenanceLog->id)->get();
                foreach ($logImages as $logImage) {
                    Storage::disk('public')->delete($logImage->image_path);
                    $logImage->delete();
                }
                $maintenanceLog->delete();
                $maintenanceLog = $newMaintenanceLog;
            } else {
                $maintenanceLog = MaintenanceLog::create([
                    'maintenance_schedule_id' => $id,
                    'log_date' => $request->get('start_date') ?? now(),
                    'remark' => $request->get('remark') ?? '',
                    'result' => $request->get('result') ?? ''
                ]);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th, 'Đã xảy ra lỗi. Vui lòng thử lại sau.');
        }

        return $this->success($maintenanceLog);
    }

    public function destroy($id)
    {
        MaintenanceSchedule::destroy($id);
        return response()->json(null, 204);
    }

    //import Maintenance Schedule from Excel
    public function import(Request $request)
    {
        // $request->validate([
        //     'file' => 'required|mimes:xlsx,xls',
        // ]);

        $path = $request->file('file');
        MaintenanceCategory::truncate(); //Truncate MaintenanceCategory table
        MaintenanceItem::truncate(); //Truncate MaintenanceItem table
        MaintenancePlan::truncate(); //Truncate MaintenancePlan table
        MaintenanceSchedule::truncate(); //Truncate MaintenanceSchedule table
        MaintenanceLog::truncate(); //Truncate MaintenanceLog table
        MaintenanceLogImage::truncate(); //Truncate MaintenanceLogImage table

        //Using maatwebsite/excel to read excel file
        $data = Excel::import(new MaintenanceScheduleImport, $path);

        return $data;
    }
}
