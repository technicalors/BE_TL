<?php

namespace App\Models;

use Awobaz\Compoships\Compoships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestCriteriaDetailHistory extends Model
{
    use HasFactory;
    protected $fillable = ['input', 'test_criteria_history_id', 'result', 'type', 'test_criteria_id'];

    public function testCriteriaHistory(){
        return $this->belongsTo(TestCriteriaHistory::class, 'test_criteria_history_id');
    }

    public function testCriteria(){
        return $this->belongsTo(TestCriteria::class, 'test_criteria_id');
    }
}
