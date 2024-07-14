<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceLogImage;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaintenanceLogImageController extends Controller
{
    use API;
    public function index()
    {
        return MaintenanceLogImage::all();
    }

    public function show($id)
    {
        return MaintenanceLogImage::find($id);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $this->validate($request, [
                'maintenance_log_id' => 'required',
                'image_path' => 'required|string',
            ]);
            // $logImages = MaintenanceLogImage::where('maintenance_log_id', $request->get('maintenance_log_id'))->get();
            // foreach($logImages as $logImage) {
            //     Storage::disk('public')->delete($logImage->image_path);
            //     $logImage->delete();
            // }
            $maintenanceLogImage = MaintenanceLogImage::create([
                'maintenance_log_id' => $request->get('maintenance_log_id'),
                'image_path' => $request->get('image_path'),
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            
            return $this->failure('', 'Đã xảy ra lỗi. Vui lòng thử lại sau.');
        }
        
        return $this->success($maintenanceLogImage);
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'maintenance_log_id' => 'required|uuid',
            'image_path' => 'required|string',
        ]);

        $maintenanceLogImage = MaintenanceLogImage::findOrFail($id);
        $maintenanceLogImage->update($request->all());
        return $this->success($maintenanceLogImage);
    }

    public function destroy($id)
    {
        MaintenanceLogImage::destroy($id);
        return response()->json(null, 204);
    }

    public function upload(Request $request)
    {
        $this->validate($request, [
            'image' => 'required|image|max:10240', // max 10MB
        ]);

        $path = $request->file('image')->store('maintenance-log-images', 'public');
        return $this->success(['path' => $path]);
    }
}
