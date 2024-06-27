<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;
    protected $table = "customer";
    protected $casts = [
      
        'id'=>'string',
        'created_at'=>'string',
        'id'=>'string',
    ];
}
