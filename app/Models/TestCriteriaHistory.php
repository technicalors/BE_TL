<?php

namespace App\Models;

use Awobaz\Compoships\Compoships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestCriteriaHistory extends Model
{
    use HasFactory, Compoships;
    protected $fillable = ['q_c_history_id', 'type', 'result', 'user_id'];

    public function qcHistory(){
        return $this->belongsTo(QCHistory::class);
    }
    public function testCriteriaDetailHistories(){
        return $this->hasMany(TestCriteriaDetailHistory::class);
    }
}
