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
