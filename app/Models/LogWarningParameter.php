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
            $device_id = '22d821e0-45bd-11ef-b8c3-a13625245eca';
            $params = (array) $request->all();
            $machine_id = $request->machine_id ?? null;
            if (isset($request->device_id)) {
                if ($request->device_id == $device_id) { // May LH
                    $machine = Machine::where('device_id', $request->device_id)->first();
                    if (!empty($machine)) {
                        $machine_id = $machine->id;
                    }
                }
            }

            if (isset($machine_id)) {
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
                        if (($f1 || $f2) && $f3) {
                            $check_log = LogWarningParameter::where('parameter_id', $key)->where('machine_id', $machine_id)->first();
                            if (!empty($check_log)) {
                                $check_log->value = $value;
                                $check_log->save();
                                $check_monitor = Monitor::where('parameter_id', $key)->where('machine_id', $machine_id)->where('status', 0)->first();
                                if ($check_monitor) {
                                    $check_monitor->update(['content' => $tm->hang_muc . ': ' . $value]);
                                } else {
                                    if ($key == 'PLC_CB01') {
                                        $m = Monitor::where('parameter_id', $key)->where('machine_id', $machine_id)->where('status', 1)->whereNull('troubleshoot')->orderByDesc('updated_at')->first();
                                        if (!empty($m)) {
                                            // Log::debug('👉 Ignore monitor');
                                            continue;
                                        }
                                    }
                                    $monitor = new Monitor();
                                    $monitor->type = 'cl';
                                    $monitor->content =  $tm->hang_muc;
                                    $monitor->value =  $value;
                                    $monitor->parameter_id = $key;
                                    $monitor->machine_id = $machine_id;
                                    $monitor->status = 0;
                                    $monitor->save();
                                }
                            } else {
                                $log = new LogWarningParameter();
                                $log->parameter_id = $key;
                                $log->value = $value;
                                $log->machine_id = $machine_id;
                                $log->save();
                            }
                        } elseif ($f4 && $f5 && $f3) {
                            LogWarningParameter::where('parameter_id', $key)->where('machine_id', $machine_id)->delete();
                            Monitor::where('parameter_id', $key)->where('machine_id', $machine_id)->where('status', 0)->update(['status' => 1]);
                        }
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
