<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Material extends Model
{
    use HasFactory;
    protected $table = "material";
    public $incrementing = false;
    protected $fillable = ['id', 'name', 'material', 'color', 'quantitative', 'thickness', 'meter_per_roll', 'sheet_per_pallet'];
    // protected $casts = ["id" => "string"];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id'=>'required|unique:material,id,'. ($id ?? ""),
                'name' => 'required',
                'material' => 'required',
            ],
            [
                'id.required' => 'Không có mã NVL',
                'id.unique' => 'Mã NVL đã tồn tại',
                'name.required' => 'Không có tên NVL',
                'material.required' => 'Không có chất liệu',
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
    public function warehouse_inventories()
    {
        return $this->hasMany(WarehouseInventory::class, 'material_id', 'id');
    }
}
