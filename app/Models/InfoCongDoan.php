<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InfoCongDoan extends Model
{
    use HasFactory;
    use \Awobaz\Compoships\Compoships;

    protected $table = "info_cong_doan";

    const STATUS_PLANNED = 0;
    const STATUS_INPROGRESS = 1;
    const STATUS_COMPLETED = 2;

    protected $fillable = ['id', 'lot_id', 'lotsize', 'lo_sx', 'line_id', 'product_id', 'thoi_gian_bat_dau', 'thoi_gian_bam_may', 'thoi_gian_ket_thuc', 'sl_dau_vao_chay_thu', 'sl_dau_ra_chay_thu', 
    'sl_dau_vao_hang_loat', 'sl_dau_ra_hang_loat', 'sl_tem_vang', 'sl_ng', 'start_powerM', 'end_powerM', 'powerM', 'updated_at', 'status', 'machine_code', 'sl_kh', 'user_id', 'material_id', 'lot_plan_id'];

    static function validateStore($input)
    {
        $validated = Validator::make(
            $input,
            [
                'lot_id' => ['required'],
                'line_id' => ['required'],
            ],
            [
                'lot_id.required' => 'Cần có mã pallet/thùng',
                'line_id.unique' => 'Cần có công đoạn',
            ]
        );
        return $validated;
    }

    static function validateUpdate($input)
    {
        $validated = Validator::make(
            $input,
            [
                'line_id' => 'required',
                'thoi_gian_bat_dau' => 'nullable|date_format:Y-m-d H:i:s',
                'thoi_gian_bam_may' => 'nullable|date_format:Y-m-d H:i:s',
                'thoi_gian_ket_thuc' => 'nullable|date_format:Y-m-d H:i:s',
                'sl_dau_vao_chay_thu' => 'nullable|numeric',
                'sl_dau_ra_chay_thu' => 'nullable|numeric',
                'sl_dau_vao_hang_loat' => 'nullable|numeric',
                'sl_dau_ra_hang_loat' => 'nullable|numeric',
                'sl_tem_vang' => 'nullable|numeric',
                'sl_ng' => 'nullable|numeric',
            ],
            [
                'line_id.required' => 'Không tìm thấy công đoạn',
                'thoi_gian_bat_dau.date_format' => 'Thời gian bắt đầu không đúng định dạng',
                'thoi_gian_bam_may.date_format' => 'Thời gian bấm máy không đúng định dạng',
                'thoi_gian_ket_thuc.date_format' => 'Thời gian kết thúc không đúng định dạng',
                'sl_dau_vao_chay_thu.numeric' => 'Số lượng đầu vào vào hàng phải là số',
                'sl_dau_ra_chay_thu.numeric' => 'Số lượng đầu ra vào hàng phải là số',
                'sl_dau_vao_hang_loat.numeric' => 'Số lượng đầu vào thực tế phải là số',
                'sl_dau_ra_hang_loat.numeric' => 'Số lượng đầu ra thực tế phải là số',
                'sl_tem_vang.numeric' => 'Số lượng tem vàng phải là số',
                'sl_ng.numeric' => 'Số lượng NG phải là số',
            ]
        );
        return $validated;
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
    public function line()
    {
        return $this->belongsTo(Line::class);
    }
    public function plan()
    {
        return $this->hasOne(ProductionPlan::class, ['lo_sx', 'line_id'], ['lo_sx', 'line_id']);
    }
    public function log()
    {
        return $this->belongsTo(LSXLog::class, 'lot_id', 'lot_id');
    }
    public function spec()
    {
        return $this->hasMany(Spec::class, ['product_id', 'line_id'], ['product_id', 'line_id']);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function material()
    {
        return $this->belongsTo(Material::class);
    }
    public function qcHistory(){
        return $this->hasOne(QCHistory::class);
    }
    public function user(){
        return $this->belongsTo(CustomUser::class);
    }
    public function machine(){
        return $this->belongsTo(Machine::class, 'machine_code', 'code');
    }
    public function lotPlan(){
        return $this->belongsTo(LotPlan::class);
    }
}
