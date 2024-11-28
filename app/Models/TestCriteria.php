<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class TestCriteria extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $fillable = ['id', 'hang_muc', 'line_id', 'chi_tieu', 'tieu_chuan', 'phan_dinh', 'reference', 'is_show', 'frequency', 'so_chi_tieu'];
    protected $hidden = ['created_at', 'updated_at'];
    const MOT_MAU_TREN_MOT_CUON = '1 mẫu/1 cuộn';//Hiển thị mỗi lot 1 lần
    const MOT_MAU_TREN_MOT_CA = '1 mẫu/ 1 ca';//Hiển thị 1 lần vào lần đầu kiểm tra của ca

    public function line()
    {
        return $this->belongsTo(Line::class);
    }

    public function ref_line()
    {
        return $this->hasOne(Line::class, 'id', 'reference');
    }

    static function validateUpdate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id' => 'required:unique:test_criterias,id'.($id ? ','.$id : ""),
                // 'line_id' => 'required',
                'chi_tieu'=>'required', 
                'hang_muc'=>'required',
                'frequency' => 'required'
            ],
            [
                'id.required'=>'Không có mã chỉ tiêu',
                'id.unique' => 'Mã chỉ tiêu đã tồn tại',
                // 'line_id.required'=>'Không tìm thấy công đoạn',
                // 'chi_tieu.required'=>'Không có chỉ tiêu',
                'hang_muc.required'=>'Không có hạng mục',
                'frequency.required'=>'Không có tần suất kiểm tra',
            ]
        );
        return $validated;
    }
    public function lines()
    {
        return $this->belongsToMany(Line::class, 'test_criteria_line', 'test_criteria_id', 'line_id');
    }
}
