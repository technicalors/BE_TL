<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class ErrorHistory extends Model
{
    use HasFactory;
    protected $fillable = [
        'q_c_history_id',
        'error_id',
        'user_id',
        'type',
        'quantity',
    ];

    public function error(){
        return  $this->belongsTo(Error::class, 'error_id');
    }
    public function user(){
        return  $this->belongsTo(CustomUser::class, 'user_id');
    }
    public function qcHistory(){
        return $this->belongsTo(QCHistory::class, 'q_c_history_id');
    }
}
