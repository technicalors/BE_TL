<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupYellowStamp extends Model
{
    use HasFactory;
    protected $table = 'group_yellow_stamp';
    protected $fillable = ['id','lo_sx','line_id','quantity'];
    public function line(){
        return $this->belongsTo(Line::class, 'line_id');
    }
    
}
