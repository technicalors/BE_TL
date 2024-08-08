<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Template extends Model
{
    use HasFactory;
    protected $table = "templates";
    protected $fillable = [
        'material_id', 'quantity',
        'roll_quantity',
        'manufacture_date',
        'machine_number',
        'worker_name',
    ];
    protected $casts = [
        'material_id' => 'string',
    ];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'material_id' => 'required',
                'quantity' => 'required|integer|min:0',
            ],
            [
                'material_id.required' => 'Vui lòng nhập NVL',
                'quantity.required' => 'Vui lòng nhập số lượng',
            ]
        );
        return $validated;
    }
}
