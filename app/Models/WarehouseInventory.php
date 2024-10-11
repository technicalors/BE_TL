<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class WarehouseInventory extends Model
{
    use HasFactory;
    protected $table = "warehouse_inventories";
    protected $fillable = [
        'material_id',
        'quantity',
        'roll_quantity',
        'unit_id',
    ];
}
