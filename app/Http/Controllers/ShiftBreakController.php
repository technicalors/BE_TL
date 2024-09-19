<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Traits\API;
use Illuminate\Http\Request;

class ShiftBreakController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ShiftBreak::with('shift')->orderBy('shift_id')->orderBy('start_time');
        if(!empty($request->type_break)){
            $query->where('type_break', 'like', "%$request->type_break%");
        }
        $result = $query->get();
        return $this->success($result);
    }

    public function store(Request $request)
    {
        $request->validate([
            'shift_id' => 'required',
            'shift_breaks' => 'required|array|min:1',
            'shif_breaks.*.type_break' => 'required|string|max:255',
            'shif_breaks.*.start_time' => 'required|date_format:H:i:s',
            'shif_breaks.*.end_time' => 'required|date_format:H:i:s',
        ]);
        $shift = Shift::firstOrCreate(
            ['id'=>$request->shift_id],
            ['name' => $request->shift_id]
        );
        foreach (($request->shift_breaks ?? []) as $key => $value) {
            $value['shift_id'] = $shift->id;
            $shif_break = ShiftBreak::create($value);
        }
        return $this->success($shift);
    }

    public function show($id)
    {
        $stamp = ShiftBreak::find($id);

        if (!$stamp) {
            return $this->success('', 'ShiftBreak not found');
        }

        return $this->success($stamp);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'shift_id' => 'required',
            'shift_breaks' => 'required|array|min:1',
            'shif_breaks.*.type_break' => 'required|string|max:255',
            'shif_breaks.*.start_time' => 'required|date_format:H:i:s',
            'shif_breaks.*.end_time' => 'required|date_format:H:i:s',
        ]);
        $shift = Shift::firstOrCreate(
            ['id'=>$request->shift_id],
            ['name' => $request->shift_id]
        );
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
        $stamp = ShiftBreak::find($id);

        if (!$stamp) {
            return $this->failure('', 'ShiftBreak not found');
        }

        $stamp->delete();

        return $this->success('', 'ShiftBreak deleted');
    }
}
