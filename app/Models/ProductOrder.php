<?php

namespace App\Models;

use Awobaz\Compoships\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Validator;

class ProductOrder extends Model
{
    use HasFactory;
    protected $table = "product_orders";
    protected $fillable = ['id', 'order_number', 'customer_id', 'product_id','product_name', 'order_date', 'delivery_date', 'quantity', 'note', 'material_id', 'sl_giao_sx', 'fc_quantity', 'sl_da_giao'];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
        'product_id' => 'string',
    ];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id' => 'required|unique:product_orders,id' . ($id ? ",$id" : ''),
                'product_id'=>'required',
                'customer_id'=>'required',
                'order_number'=>'required',
                'order_date'=>'required|date_format:Y-m-d',
                'quantity'=>'required|integer|min:0',
            ],
            [
                'id.unique' => 'Mã đơn đã tồn tại',
                'product_id.required'=>'Vui lòng nhập sản phẩm',
                'quantity.required'=>'Vui lòng nhập số lượng',
                'order_date.required'=>'Vui lòng nhập ngày đặt hàng',
            ]
        );
        return $validated;
    }

    /**
     * @return HasOne
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function material()
    {
        return $this->belongsTo(Material::class);
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function numberProductOrder(){
        return $this->hasMany(NumberMachineOrder::class);
    }
}
