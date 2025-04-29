<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupYellowStampInfo extends Model
{
    use HasFactory;
    protected $table = 'group_yellow_stamp_info';
    // public $incrementing = false;
    // public $timestamps = false;
    protected $fillable = ['group_yellow_stamp_id', 'info_cong_doan_id', 'quantity', 'user_id', 'error_id'];
    public function group_yellow_stamp(){
        return $this->belongsTo(GroupYellowStamp::class, 'group_yellow_stamp_id');
    }
    public function info_cong_doan(){
        return $this->belongsTo(InfoCongDoan::class, 'info_cong_doan_id');
    }
}
