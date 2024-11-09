<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

class LogWarningParameter extends Model
{
    use HasFactory;
    protected $table = 'log_warning_parameter';
    protected $fillable = ['parameter_id', 'machine_id', 'value'];

    public static function checkParameter($request)
    {
        try {
            // $device_id = '22d821e0-45bd-11ef-b8c3-a13625245eca';
            // $params = (array) $request->all();
            // $machine_id = $request->machine_id ?? null;
            // if (isset($request->device_id)) {
            //     if ($request->device_id == $device_id) { // May LH
            //         $machine = Machine::where('device_id', $request->device_id)->first();
            //         if (!empty($machine)) {
            //             $machine_id = $machine->id;
            //         }
            //     }
            // }

            if (isset($request->device_id) && $request->device_id == '22d821e0-45bd-11ef-b8c3-a13625245eca') {
                $machine = Machine::where('device_id', $request->device_id)->first();
                $params = (array) $request->all();
                if (!empty($machine)) {
                    $machine_id = $machine->id;
                }
                $scenarios = Scenario::all();
                $mark = [];
                foreach ($scenarios as $item) {
                    $mark[$item->parameter_id] = $item;
                }
                foreach ($params as $key => $value) {
                    if (isset($mark[$key])) {
                        $tm = $mark[$key];
                        $f1 = (float)$value > (float)$tm->tieu_chuan_max;
                        $f2 = (float)$value < (float)$tm->tieu_chuan_min;
                        $f3 = $value != -1;
                        $f4 = (float)$value <= (float)$tm->tieu_chuan_kiem_soat_tren;
                        $f5 = (float)$value >= (float)$tm->tieu_chuan_kiem_soat_duoi;
                        
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('🛑 ' . $e->getMessage());
        }
    }

    public function machine(): HasOne
    {
        return $this->hasOne(Machine::class, 'id', 'machine_id');
    }

    public function scenario(): HasOne
    {
        return $this->hasOne(Scenario::class, 'parameter_id', 'parameter_id');
    }
}
