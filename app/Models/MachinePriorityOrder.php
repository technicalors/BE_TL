<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class MachinePriorityOrder extends Model
{
    use HasFactory;

    protected $fillable = ['machine_id', 'line_id', 'priority', 'product_id'];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input ?? [],
            [
                'machine_id'=>'required',
                'line_id' => 'required',
                'product_id'=>'required', 
                'priority'=>'required',
            ],
            [
                'machine_id.required'=>'Không có mã máy', 
                'line_id.required'=>'Không có công đoạn',
                'product_id.required'=>'Không có mã sản phẩm', 
                'priority.required'=>'Không có thứ tự ưu tiên', 
            ]
        );
        return $validated;
    }

    public function line(){
        return $this->belongsTo(Line::class);
    }
    public function product(){
        return $this->belongsTo(Product::class);
    }
    public function machine(){
        return $this->belongsTo(Machine::class, 'machine_id', 'code');
    }
    public function attributeValues()
    {
        return $this->hasMany(MachinePriorityOrderAttributeValue::class);
    }
}
