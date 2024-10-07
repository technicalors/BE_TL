<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Material extends Model
{
    use HasFactory;
    protected $table = "material";
    protected $fillable = ['id', 'name', 'material', 'color', 'quantitative', 'thickness', 'meter_per_roll', 'sheet_per_pallet'];
    protected $casts = ["id" => "string"];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id'=>'required|unique:material,id'.($id ? ','.$id : ""),
                'name'=>'required',
            ],
            [
                'id.required'=>'Không tìm thấy mã nguyên vật liệu',
                'id.unique'=>'Mã nguyên vật liệu đã tồn tại',
                'name.required'=>'Không tìm thấy tên nguyên vật liệu',
            ]
        );
        return $validated;
    }

    public function bom()
    {
        return $this->hasOne(Bom::class, 'material_id', 'id');
    }
    public function boms()
    {
        return $this->hasMany(Bom::class, 'material_id', 'id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'bom', 'material_id', 'product_id')
            ->withPivot('priority');
    }
}
