<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tracking extends Model
{
    use HasFactory;
    protected $table = "tracking";
    protected $fillable = ['machine_id','input','output','timestamp', 'lot_id','status','powerM'];

    public static function createx($machine_id)

    {
        $res = new Tracking();
        $res->machine_id = $machine_id;
        $res->save();
        return $res;
    }

    public static function updateData($machine_id, $input=null, $output=null)
    {
        $res = Tracking::where("machine_id", $machine_id)->first();

        if (!$res) $res = self::createx($machine_id);

        if (isset($input)) {
            $res->input = $input;
        }
        if (isset($output)) {
            $res->output = $output;
        }

        // dd($input,$output,$res);

        $res->save();
        return $res;
    }

    public static function getData($machine_id)
    {
        $res = Tracking::where("machine_id", $machine_id)->first();
        if (!$res) $res = self::createx($machine_id);
        return $res;
    }
}
