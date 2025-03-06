<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MachinePriorityOrder extends Model
{
    use HasFactory;

    protected $fillable = ['machine_id', 'line_id', 'priority', 'product_id'];

    static function validate($input, $id = null)
    {
        Log::debug($input);
        $validated = Validator::make(
            $input ?? [],
            [
                'machine_id'=>'required|exists:machines,code',
                'line_id' => 'required|exists:lines,id',
                'product_id'=>'required|exists:products,id', 
                'priority'=>'required',
            ],
            [
                'machine_id.required'=>'Không có mã máy', 
                'machine_id.exists'=>'Không tồn tại mã máy',
                'line_id.required'=>'Không có công đoạn',
                'line_id.exists'=>'Không tồn tại công đoạn',
                'product_id.required'=>'Không có mã sản phẩm', 
                'product_id.exists'=>'Không tồn tại sản phẩm',
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
