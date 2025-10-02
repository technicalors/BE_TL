<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class YellowStampHistory extends Model
{
    use HasFactory;
    protected $fillable = [
        'q_c_history_id',
        'errors',
        'sl_tem_vang',
        'user_id',
    ];

    public function qcHistory(){
        return $this->belongsTo(QCHistory::class, 'q_c_history_id');
    }
}
