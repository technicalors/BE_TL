<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class ProductionMode extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','name','line_id', 'value'];
}
