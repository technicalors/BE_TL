<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyPowerConsume extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'machine_code',
        'start_value',
        'end_value',
        'date',
    ];
}
