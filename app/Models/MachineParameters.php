<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MachineParameters extends Model
{
    use HasFactory;

    protected $fillable=['machine_id','parameter_id'];

    public function scenario(): HasOne
    {
        return $this->hasOne(Scenario::class, 'parameter_id', 'parameter_id');
    }
}
