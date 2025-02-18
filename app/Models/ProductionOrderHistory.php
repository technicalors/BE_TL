<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderHistory extends Model
{
    use HasFactory;
    protected $table = "production_order_histories";
    protected $fillable = ['production_order_id', 'line_id', 'order_quantity', 'actual_quantity'];

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductOrder::class, 'production_order_id');
    }
}
