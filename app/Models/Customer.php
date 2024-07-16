<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Customer extends Model
{
    use HasFactory;
    protected $table = "customer";
    protected $fillable = ['id', 'name', 'thong_tin'];
    protected $casts = [
        'id'=>'string',
        'name'=>'string',
        'created_at'=>'string',
    ];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id'=>'required|unique:customer,id'.($id ? ','.$id : ""),
                'name'=>'required',
            ],
            [
                'id.required'=>'Không tìm thấy khách hàng',
                'id.unique'=>'Khách hàng đã tồn tại',
                'name.required'=>'Không tìm thấy tên khách hàng',
            ]
        );
        return $validated;
    }
}
