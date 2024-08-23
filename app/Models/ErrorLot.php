<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class ErrorLot extends Model
{
    use HasFactory;
    protected $fillable = [
        'line_id',
        'lot_id',
        'machine_code',
        'error_id',
        'value',
        'user_id',
        'type'
    ];
}
