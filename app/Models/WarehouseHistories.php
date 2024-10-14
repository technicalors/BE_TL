<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class WarehouseHistories extends Model
{
    use HasFactory;
    protected $table = "warehouse_histories";
    protected $fillable = [
        'roll_id',
        'type',
        'roll_id',
        'material_id',
        'quantity',
        'roll_quantity',
        'unit_id',
        'note',
    ];

    public const TYPE_IMPORT = 'import';
    public const TYPE_EXPORT = 'export';
}
