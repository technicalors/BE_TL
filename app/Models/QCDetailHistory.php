<?php

namespace App\Models;

use Awobaz\Compoships\Compoships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QCDetailHistory extends Model
{
    use HasFactory;
    protected $fillable = ['q_c_history_id', 'input', 'test_criteria_id', 'result'];
}
