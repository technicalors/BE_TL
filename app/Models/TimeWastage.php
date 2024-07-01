<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class TimeWastage extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','type','line_id', 'value'];
}
