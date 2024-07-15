<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class ProductOrder extends Model
{
    use HasFactory;
    protected $table = "product_orders";
    protected $fillable = ['product_id', 'order_date', 'delivery_date', 'quantity', 'note'];
    protected $casts = [
        'product_id' => 'string',
    ];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'product_id'=>'required',
                'order_date'=>'required|date_format:Y-m-d',
                'delivery_date'=>'date_format:Y-m-d',
                'quantity'=>'required|integer|min:0',
            ],
            [
                'product_id.required'=>'Vui lòng nhập sản phẩm',
                'quantity.required'=>'Vui lòng nhập số lượng',
                'order_date.required'=>'Vui lòng nhập ngày đặt hàng',
            ]
        );
        return $validated;
    }
}
