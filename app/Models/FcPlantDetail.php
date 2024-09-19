<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FcPlantDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'fc_plant_id',
        'col',
        'value',
    ];
}
