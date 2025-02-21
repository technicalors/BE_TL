<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderPriority extends Model
{
    use HasFactory;
    protected $table = 'production_order_priorities';
    protected $fillable = ['production_order_id', 'confirm_date', 'product_id', 'priority','new_order_quantity','fc_order_quantity','outstanding_order','production_quantity'];

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
        return $this->hasMany(ProductionOrderHistory::class, 'product_id','product_id')->orderBy('id', 'DESC');
    }

    public static function removeItemAndReorderList($product_id){
        $delete = ProductionOrderPriority::where('product_id', $product_id ?? null)->delete();
        $all = ProductionOrderPriority::all();
        foreach ($all as $key => $value) {
            $value->priority = ($key + 1);
            $value->save();
        }
    }
}
