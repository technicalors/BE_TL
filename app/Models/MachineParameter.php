<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineParameter extends Model
{
    use HasFactory;

    protected $fillable=['machine_id', 'is_if', 'parameter_id'];
}
