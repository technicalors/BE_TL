<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftBreak extends Model
{
    use HasFactory;
    protected $fillable = [
        'shift_id',
        'type_break',
        'start_time',
        'end_time',
    ];
}
