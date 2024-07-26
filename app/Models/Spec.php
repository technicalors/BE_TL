<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Spec extends Model
{
    use HasFactory;
    use \Awobaz\Compoships\Compoships;
    protected $table = "spec";
    protected $fillable = [
        'product_id',
        'line_id',
        'slug',
        'value',
        'name',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function line(){
        return $this->belongsTo(Line::class);
    }
}
