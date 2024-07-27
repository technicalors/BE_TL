<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Assignment extends Model
{
    use HasFactory;
    protected $fillable = ['worker_id','lot_id','assigned_quantity', 'actual_quantity', 'ok_quantity'];

    public function worker(){
        return $this->belongsTo(Workers::class, 'worker_id');
    }
    public function lot(){
        return $this->belongsTo(Lot::class, 'lot_id');
    }
}
