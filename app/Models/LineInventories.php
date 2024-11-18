<?php

namespace App\Models;

use App\Traits\IDTimestamp;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class LineInventories extends Model
{
    use HasFactory;
    protected $fillable = ['line_id', 'product_id', 'quantity'];
    public function line(){
        return $this->belongsTo(Line::class, 'line_id');
    }
    public function product(){
        return $this->belongsTo(Product::class, 'product_id');
    }
}
