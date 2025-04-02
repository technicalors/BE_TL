<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NGTracking extends Model
{
    use HasFactory;
    protected $table = "ng_tracking";
    protected $fillable = ['info_cong_doan_id','start_quantity','end_quantity','ng_quantity', 'user_id', 'status'];

    const STOPPED_STATUS = 2;
    const TRACKING_STATUS = 1;
    const PAUSING_STATUS = 0;
    const COMPLETE_STATUS = 3;
}
