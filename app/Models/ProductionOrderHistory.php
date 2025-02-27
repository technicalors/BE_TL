<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderHistory extends Model
{
    use HasFactory;
    protected $table = "production_order_histories";
    protected $fillable = ['line_id', 'product_id', 'produced_quantity', 'inventory_quantity', 'order_quantity','component_id','lo_sx'];

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductOrder::class, 'product_id', '');
    }
    public function productionOrderPriority()
    {
        return $this->belongsTo(ProductionOrderPriority::class, 'product_id', 'product_id');
    }
}
