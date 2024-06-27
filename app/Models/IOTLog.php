<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IOTLog extends Model
{
    use HasFactory;

    protected $table = "IOT_LOG";

    protected $casts=[
        "data"=>"json"
    ];
}
