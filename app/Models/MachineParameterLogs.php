<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineParameterLogs extends Model
{
    use HasFactory;
    protected $fillable = ['id', 'machine_id', 'data_if', 'data_input', 'ca_sx_id', 'start_time', 'end_time'];
    protected $casts = ['data_input' => 'json', 'data_if'=>'json'];
}
