<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class LineStandard extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','value','line_id', 'name', 'name_slug', 'type', 'type_slug'];
}
