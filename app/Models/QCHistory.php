<?php

namespace App\Models;

use Awobaz\Compoships\Compoships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QCHistory extends Model
{
    use HasFactory, Compoships;
    protected $fillable = ['info_cong_doan_id', 'user_id', 'eligible_to_end', 'scanned_time', 'line_id', 'machine_id'];

    public function infoCongDoan(){
        return $this->belongsTo(InfoCongDoan::class);
    }
    public function user(){
        return $this->belongsTo(CustomUser::class);
    }
    public function testCriteriaHistories(){
        return $this->hasMany(TestCriteriaHistory::class);
    }
    public function yellowStampHistories(){
        return $this->hasMany(YellowStampHistory::class);
    }
    public function errorHistories(){
        return $this->hasMany(ErrorHistory::class);
    }
    const NOT_READY_TO_END = 0;
    CONST READY_TO_END = 1;
}
