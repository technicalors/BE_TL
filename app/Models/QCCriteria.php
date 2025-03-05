<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class QCCriteria extends Model
{
    use HasFactory;
    protected $table = 'qc_criteria';
    protected $fillable = ['product_id','line_id','criteria_name', 'criteria_value'];

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
