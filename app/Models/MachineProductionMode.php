<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class MachineProductionMode extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','machine_id','value', 'material_waste', 'line_production_waste', 'prep_time', 'transportation_waste', 'roll_change_time', 'input_quantity', 'hourly_output', 'operator_count'];
}
