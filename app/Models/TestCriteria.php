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
    protected $fillable = ['id', 'hang_muc', 'line_id', 'chi_tieu', 'tieu_chuan', 'phan_dinh', 'reference', 'is_show', 'frequency'];
    protected $hidden = ['created_at', 'updated_at'];
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
            ],
            [
                'id.required'=>'Không có mã chỉ tiêu',
                'id.unique' => 'Mã chỉ tiêu đã tồn tại',
                // 'line_id.required'=>'Không tìm thấy công đoạn',
                // 'chi_tieu.required'=>'Không có chỉ tiêu',
                'hang_muc.required'=>'Không có hang mục', 
            ]
        );
        return $validated;
    }
    public function lines()
    {
        return $this->belongsToMany(Line::class, 'test_criteria_line', 'test_criteria_id', 'line_id');
    }
}
