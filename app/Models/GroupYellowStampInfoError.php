<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupYellowStampInfoError extends Model
{
    use HasFactory;
    protected $table = 'group_yellow_stamp_info_error';
    // public $incrementing = false;
    // public $timestamps = false;
    protected $fillable = ['group_yellow_stamp_info_id', 'error_id', 'quantity'];
    public function group_yellow_stamp(){
        return $this->belongsTo(GroupYellowStampInfo::class, 'group_yellow_stamp_info_id');
    }
}
