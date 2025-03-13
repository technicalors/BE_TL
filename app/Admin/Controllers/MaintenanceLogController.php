<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceLogImage;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MaintenanceLogController extends Controller
{
    use API;
    public function index()
    {
        $data = MaintenanceLog::all();
        return $this->success($data);
    }

    public function show($id)
    {
        return MaintenanceLog::find($id);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        if(isset($input['complete'])){
            $input['log_date'] = Carbon::now()->setTimezone('Asia/Ho_Chi_Minh');
            $input['result'] = 'OK';
            $input['note'] = '';
        }else{
            $input['log_date'] = Carbon::parse($input['log_date'])->setTimezone('Asia/Ho_Chi_Minh');
        }
        $maintenanceLog = MaintenanceLog::updateOrCreate(['maintenance_schedule_id'=>$input['maintenance_schedule_id']], $input);
        if(isset($input['evidence'])){
            $file = $request->file('evidence');
            $filename = 'evidence_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('maintenance-log-images'), $filename);
            $filePath = 'maintenance-log-images/' . $filename;
            MaintenanceLogImage::create(
                ['maintenance_log_id' => $maintenanceLog->id, 'image_path' => $filePath]
            );
        }
        return $this->success($maintenanceLog, 'Ghi nhận thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $maintenanceLog = MaintenanceLog::findOrFail($id);
        if(isset($input['complete']) && $input['complete'] === true){
            $input['log_date'] = Carbon::now()->setTimezone('Asia/Ho_Chi_Minh');
            $input['result'] = 'OK';
            $input['note'] = '';
        }else{
            $input['log_date'] = Carbon::parse($input['log_date'])->setTimezone('Asia/Ho_Chi_Minh');
        }
        $maintenanceLog->update($input);
        if(isset($input['evidence'])){
            $file = $request->file('evidence');
            $filename = 'evidence_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('maintenance-log-images'), $filename);
            $filePath = 'maintenance-log-images/' . $filename;
            MaintenanceLogImage::create(
                ['maintenance_log_id' => $maintenanceLog->id, 'image_path' => $filePath]
            );
        }
        return $this->success($maintenanceLog, 'Cập nhật thành công');
    }

    public function destroy($id)
    {
        $images = MaintenanceLogImage::where('maintenance_log_id', $id)->get();
        foreach($images as $image){
            $filePath = public_path($image->image_path);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $image->delete();
        }
        $maintenanceLog = MaintenanceLog::destroy($id);
        return $this->success($maintenanceLog, 'Xoá thành công');
    }

    public function completeAll(Request $request){
        $input = $request->all();
        foreach ($input['data'] as $key => $value) {
            $log = MaintenanceLog::firstOrCreate(
                ['maintenance_schedule_id' => $value['maintenance_schedule_id']],
                ['log_date'=>Carbon::now()->setTimezone('Asia/Ho_Chi_Minh'), 'result' => 'OK', 'note' => ""]
            );
        }
        return $this->success('');
    }
}
