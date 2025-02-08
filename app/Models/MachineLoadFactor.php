<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineLoadFactor extends Model
{
    use HasFactory;
    protected $fillable = ['machine_code', 'date', 'fixed_productivity_per_hour', 'loaded_quantity', 'work_hours', 'fixed_hours'];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function machine(){
        return $this->belongsTo(Machine::class, 'machine_code', 'code');
    }
}
