<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Error extends Model
{
    use HasFactory;
    protected $fillable = ['id', 'name', 'noi_dung', 'nguyen_nhan', 'khac_phuc', 'phong_ngua', 'line_id'];

    protected $casts = [
        "id" => "string"
    ];

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    static function validateUpdate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id'=>'required|unique:errors,id'. ($id ? ",$id" : ""),
                // 'noi_dung'=>'required',
                // 'line_id' => 'required',
                // 'nguyen_nhan'=>'required', 
                // 'khac_phuc'=>'required',
                // 'phong_ngua'=>'required',
            ],
            [
                'id.required' => 'Không có mã lỗi',
                'id.unique' => 'Mã lỗi đã tồn tại',
                // 'noi_dung.required'=>'Không có nội dung', 
                // 'line_id.required'=>'Không tìm thấy công đoạn',
                // 'nguyen_nhan.required'=>'Không có nguyên nhân',
                // 'khac_phuc.required'=>'Không có khắc phục', 
                // 'phong_ngua.required'=>'Không có phòng ngừa', 
            ]
        );
        return $validated;
    }
}
