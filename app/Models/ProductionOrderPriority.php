<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderPriority extends Model
{
    use HasFactory;
    protected $table = 'production_order_priorities';
    protected $fillable = ['production_order_id', 'confirm_date', 'product_id', 'priority'];

    public function productionOrder()
    {
        return $this->belongsTo(ProductOrder::class, 'production_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productionOrderHistory()
    {
        return $this->hasMany(ProductionOrderHistory::class, 'production_order_id','production_order_id');
    }

    public static function removeItemAndReorderList($production_order_id){
        $delete = ProductionOrderPriority::where('production_order_id', $production_order_id ?? null)->delete();
        $all = ProductionOrderPriority::all();
        foreach ($all as $key => $value) {
            $value->priority = ($key + 1);
            $value->save();
        }
    }
}
