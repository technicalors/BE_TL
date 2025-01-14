<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class RollMaterial extends Model
{
    use HasFactory;
    protected $table = "roll_materials";
    protected $fillable = [
        'id',
        'template_id',
        'material_id',
        'quantity',
        'roll_quantity',
        'unit_id',
    ];
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
    public function warehouse_inventory()
    {
        return $this->hasOne(WarehouseInventory::class, 'roll_id');
    }
}
