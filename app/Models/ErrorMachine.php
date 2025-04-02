<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;
use Illuminate\Support\Facades\Validator;

class ErrorMachine extends Model
{
    use HasFactory;
    protected $table = "error_machine";
    protected $fillable = [
        'code',
        'line_id',
        'noi_dung',
        'type',
        'nguyen_nhan',
        'khac_phuc',
        'phong_ngua',
    ];

    const ERROR_TYPE = [1=>'Máy lỗi', 2=>'Dừng nghỉ'];

    public function line(){
        return $this->belongsTo(Line::class, 'line_id');
    }

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                // 'code'=>'required|unique:error_machine,code'. ($id ? ",$id" : ""),
                'code'=>'required',
                'noi_dung'=>'required',
                'line_id' => 'required',
                'type' => 'required'
            ],
            [
                'code.required' => 'Không có mã lỗi',
                // 'code.unique' => 'Mã lỗi đã tồn tại',
                'noi_dung.required'=>'Không có nội dung', 
                'line_id.required'=>'Không tìm thấy công đoạn',
                'type.required'=>'Không có loại lỗi',
                // 'nguyen_nhan.required'=>'Không có nguyên nhân',
                // 'khac_phuc.required'=>'Không có khắc phục', 
                // 'phong_ngua.required'=>'Không có phòng ngừa', 
            ]
        );
        return $validated;
    }
}
