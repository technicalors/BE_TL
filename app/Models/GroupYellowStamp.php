<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupYellowStamp extends Model
{
    use HasFactory;
    protected $table = 'group_yellow_stamp';
    protected $fillable = ['id','lo_sx','line_id','quantity', 'machine_id'];
    public function line(){
        return $this->belongsTo(Line::class, 'line_id');
    }
    public function losx(){
        return $this->belongsTo(Losx::class, 'lo_sx');
    }
    public function info_cong_doan(){
        return $this->hasManyThrough(InfoCongDoan::class, GroupYellowStampInfo::class, 'group_yellow_stamp_id', 'id', 'id', 'info_cong_doan_id');
    }
    public function lot(){
        return $this->hasManyThrough(Lot::class, GroupYellowStampLot::class, 'group_yellow_stamp_id', 'id', 'id', 'lot_id');
    }
}
