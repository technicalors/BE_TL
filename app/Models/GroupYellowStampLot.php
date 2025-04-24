<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupYellowStampLot extends Model
{
    use HasFactory;
    protected $table = 'group_yellow_stamp_lot';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['group_yellow_stamp_id', 'lot_id'];
    public function group_yellow_stamp(){
        return $this->belongsTo(GroupYellowStamp::class, 'group_yellow_stamp_id');
    }
    public function lot(){
        return $this->belongsTo(Lot::class, 'lot_id');
    }
}
