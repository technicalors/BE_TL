<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineParameters extends Model
{
    use HasFactory;

    protected $fillable=['machine_id','parameter_id'];
}
