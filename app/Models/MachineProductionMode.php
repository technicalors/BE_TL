<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class MachineProductionMode extends Model
{
    use HasFactory;
    protected $table = 'machine_production_modes';
    protected $fillable = ['product_id','machine_id','parameter_name', 'standard_value'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id', 'code');
    }
}
