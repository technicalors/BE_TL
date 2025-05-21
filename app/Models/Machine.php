<?php

namespace App\Models;

use App\Traits\UUID;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Complex\rho;

class Machine extends Model
{
    use HasFactory, UUID;
    protected $fillable = ['name', 'code', 'is_iot', 'line_id', 'kieu_loai', 'cong_suat', 'hang_sx', 'nam_sd', 'don_vi_sd', 'ma_so', 'tinh_trang', 'vi_tri', 'display', 'device_id', 'available_at'];
    protected $hidden = ['created_at', 'updated_at'];

    public function plan()
    {
        return $this->hasMany(ProductionPlan::class, 'machine_id', 'code');
    }

    public function reason()
    {
        return $this->belongsToMany(Reason::class, 'reason_machine')->withTimestamps()->wherePivot("created_at", ">=", Carbon::today());
    }

    public function parameter()
    {
        return $this->hasMany(MachineParameter::class)->orderBy('is_if');
    }
    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }

    public function latest()
    {
        return $this->hasOne(MachineParameter::class)->latestOfMany();
    }


    public function lsxLog()
    {
        $lsx_ids = ProductionPlan::where('machine_id', $this->id)->get()->pluck('soLSX');
        return LSXLog::whereIn("lsx", $lsx_ids);
    }

    public function parameters()
    {
        return $this->hasManyThrough(Parameters::class, MachineParameters::class, 'machine_id', 'id', 'code', 'parameter_id');
    }

    public function status()
    {
        return $this->hasOne(MachineStatus::class, 'machine_id', 'code');
    }

    public function info_cong_doan()
    {
        return $this->hasMany(InfoCongDoan::class, 'line_id', 'line_id');
    }

    public function device()
    {
        return $this->hasOne(Machine::class, 'id', 'device_id');
    }

    static function validateUpdate($input)
    {
        $validated = Validator::make(
            $input,
            [
                'line_id' => 'required',
                'name' => 'required',
                'code' => 'required',
                'line_id' => 'required',
                // 'kieu_loai' => 'required',
                // 'cong_suat' => 'required',
                'hang_sx' => 'required',
                // 'nam_sd' => 'required',
                'don_vi_sd' => 'required',
                'ma_so' => 'required',
                'tinh_trang' => 'required',
                'vi_tri' => 'required',
                // 'is_iot' => 'required',
            ],
            [
                'line_id.required' => 'Không tìm thấy công đoạn',
                'name.required' => 'Không có tên máy',
                // 'kieu_loai.required' => 'Không có kiểu loại',
                'code.required' => 'Không có mã máy',
                // 'is_iot.required' => 'Không giá trị là IF',
                // 'cong_suat.required' => 'Không có công suất',
                'hang_sx.required' => 'Không có hãng sản xuất',
                // 'nam_sd.required' => 'Không có năm sử dụng',
                'don_vi_sd.required' => 'Không có đơn vị sử dụng',
                'ma_so.required' => 'Không có mã số',
                'tinh_trang.required' => 'Không có tình trạng',
                'vi_tri' => 'Không có vị trí',
            ]
        );
        return $validated;
    }
    public function machineShifts()
    {
        return $this->hasMany(MachineShift::class, 'machine_id', 'code');
    }
}
