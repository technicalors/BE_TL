<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QCHistory extends Model
{
    use HasFactory;
    protected $fillable = ['lot_id', 'lo_sx', 'machine_code', 'line_id', 'log', 'scanned_time', 'user_id'];
    protected $casts = ['log' => 'json'];
}
