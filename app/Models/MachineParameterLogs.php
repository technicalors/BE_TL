<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MachineParameterLogs extends Model
{
    use HasFactory;
    protected $fillable = ['id', 'machine_id', 'data_if', 'data_input', 'ca_sx_id', 'start_time', 'end_time'];
    protected $casts = ['data_input' => 'json', 'data_if'=>'json'];

    public function machineParameter(): HasOne
    {
        return $this->hasOne(MachineParameters::class, 'machine_id', 'machine_id')->whereColumn('parameter_id', '=', 'machine_parameters.parameter_id');
    }
}
