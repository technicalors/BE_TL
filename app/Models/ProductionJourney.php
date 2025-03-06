<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class ProductionJourney extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','line_id','production_order', 'material_waste', 'line_production_waste', 'prep_time', 'transportation_waste', 'roll_change_time', 'input_quantity', 'hourly_output', 'operator_count'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'product_id'=>'required|exists:products,id',
                'line_id'=>'required|exists:lines,id',
            ],
            [
                'product_id.required'=>'Không có sản phẩm',
                'line_id.required'=>'Không có công đoạn',
                'product_id.exists'=>'Không tồn tại sản phẩm',
                'line_id.exists'=>'Không tồn tại công đoạn',
            ]
        );
        return $validated;
    }
}
