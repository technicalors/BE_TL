<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class UserInfo extends Model
{
    use HasFactory;
    protected $table = "user_infos";
    protected $fillable = ['id', 'name', 'date_join_company', 'date_end_trial', 'category1', 'category2', 'category3', 'category4', 'category5', 'note'];
    protected $casts = [
        'id' => 'string',
    ];

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id'=>'required|unique:user_infos,id'.($id ? ','.$id : ""),
                'name'=>'required',
                'date_join_company'=>'required|date_format:Y-m-d',
            ],
            [
                'id.required'=>'Vui lòng nhập mã nhân sự',
                'name.required'=>'Vui lòng nhập tên nhân sự',
                'date_join_company.required'=>'Vui lòng nhập ngày vào làm',
            ]
        );
        return $validated;
    }
}
