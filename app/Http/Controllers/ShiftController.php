<?php

namespace App\Http\Controllers;

use App\Models\MachineShift;
use App\Models\Shift;
use App\Models\ShiftBreak;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    use API;

    public function index()
    {
        $shifts = Shift::all();
        return $this->success($shifts);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'shift_breaks' => 'required|array|min:1',
            'shif_breaks.*.type_break' => 'required|string|max:255',
            'shif_breaks.*.start_time' => 'required|date_format:H:i:s',
            'shif_breaks.*.end_time' => 'required|date_format:H:i:s',
        ]);
        $shift = Shift::create(['name' => $request->name]);
        foreach (($request->shift_breaks ?? []) as $key => $value) {
            $value['shift_id'] = $shift->id;
            $shif_break = Shift::create($value);
        }
        return $this->success($shift);
    }

    public function show($id)
    {
        $shift = Shift::with('shift_breaks')->find($id);

        if (!$shift) {
            return $this->failure('', 'Không tìm thấy ca');
        }

        return $this->success($shift);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'shift_breaks' => 'required|array|min:1',
            'shif_breaks.*.type_break' => 'required|string|max:255',
            'shif_breaks.*.start_time' => 'required|date_format:H:i:s',
            'shif_breaks.*.end_time' => 'required|date_format:H:i:s',
        ]);
        $shift = Shift::find($id);
        if(!$shift){
            return $this->failure('', 'Không tìm thấy ca');
        }
        $shift->update($request->all());
        foreach (($request->shift_breaks ?? []) as $key => $value) {
            
            $value['shift_id'] = $shift->id;
            $shif_break = ShiftBreak::find($value['id'] ?? null);
            if($shif_break){
                $shif_break->update($value);
            }else{
                $shif_break = ShiftBreak::create($value);
            }
        }
        return $this->success($shift);
    }

    public function destroy($id)
    {
        $shift = Shift::with('shift_breaks')->find($id);

        if (!$shift) {
            return $this->failure('', 'Không tìm thấy ca');
        }
        try {
            DB::beginTransaction();
            foreach ($shift->shift_breaks as $key => $shift_break) {
                $shift_break->delete();
            }
            $shift->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Đã xoá ca');
    }
}
