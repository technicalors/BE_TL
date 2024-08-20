<?php

namespace App\Models;

use Awobaz\Compoships\Compoships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QCHistory extends Model
{
    use HasFactory, Compoships;
    protected $fillable = ['lot_id', 'lo_sx', 'machine_code', 'line_id', 'log', 'scanned_time', 'user_id'];
    protected $casts = ['log' => 'json'];

    public function infoCongDoan(){
        return $this->hasOne(InfoCongDoan::class, ['machine_code', 'lot_id', 'line_id'], ['machine_code', 'lot_id', 'line_id']);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
    public function line(){
        return $this->belongsTo(Line::class);
    }
    public function machine(){
        return $this->belongsTo(Machine::class, 'machine_code');
    }
    public function plan(){
        return $this->belongsTo(ProductionPlan::class, ['line_id', 'lo_sx', 'machine_code'], ['line_id', 'lo_sx', 'machine_id']);
    }
}
