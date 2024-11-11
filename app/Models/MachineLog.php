<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineLog extends Model
{
    use HasFactory;
    protected $fillable = ['machine_id', 'info'];
    protected $casts = [
        "info" => "json"
    ];
    public function machine()
    {
        return $this->belongsTo(Machine::class, "machine_id", "code");
    }

    static public function getLatestRecord($machine_id)
    {
        return MachineLog::where("machine_id", $machine_id)->orderBy("created_at", "desc")->get()->first();
    }



    public static function UpdateStatus($request)
    {
        $isRun  = $request->status;
        $res = self::getLatestRecord($request->machine_id);
        if ((int)$isRun == 1 && isset($res) && !isset($res->info['end_time'])) {
            $info = $res->info;
            $info['end_time'] = strtotime(now());
            $res->info = $info;
            $res->save();
            return $res;
        }
        $tracking = Tracking::where('machine_id', $request->machine_id)->first();
        if (((int)$isRun == 0 || (int)$isRun == 2) && (!isset($res) || isset($res->info['end_time']))) {
            $res = new MachineLog();
            $res->machine_id = $request->machine_id;
            $info = [
                "start_time" => strtotime(now()),
            ];
            if ($tracking->lot_id) {
                $info['lot_id'] = $tracking->lot_id;
            }
            $info['type'] = $request->type;
            $res->info = $info;
            $res->save();
            // $monitor = Monitor::where('machine_id', $request->machine_id)->where('type', 'tb')->where('status', 0)->first();
            // if (!$monitor) {
            //     $record = new Monitor();
            //     $record->type = 'tb';
            //     $record->machine_id = $request->machine_id;
            //     $record->content = 'Sự cố máy';
            //     $record->status = 0;
            //     $record->save();
            // }
            return $res;
        }

        return $res;
    }
}
