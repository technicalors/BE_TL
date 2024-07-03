<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Bom extends Model
{
    use HasFactory;
    protected $table = "bom";
    protected $fillable = ['product_id','material_id','ratio', 'priority'];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function material(){
        return $this->belongsTo(Material::class);
    }

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'product_id'=>'required|exists:products,id',
                'material_id'=>'required|exists:material,id',
            ],
            [
                'product_id.required'=>'Không có sản phẩm',
                'material_id.required'=>'Không có nguyên vật liệu',
                'product_id.exists'=>'Không tồn tại sản phẩm',
                'material_id.exists'=>'Không tồn tại nguyên vật liệu',
            ]
        );
        return $validated;
    }
}
