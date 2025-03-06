<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class ProductCustomer extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','customer_id'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'product_id'=>'required|exists:products,id',
                'customer_id'=>'required|exists:customer,id',
            ],
            [
                'product_id.required'=>'Không có sản phẩm',
                'customer_id.required'=>'Không có khách hàng',
                'product_id.exists'=>'Không tồn tại sản phẩm',
                'customer_id.exists'=>'Không tồn tại khách hàng',
            ]
        );
        return $validated;
    }
}
