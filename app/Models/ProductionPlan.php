<?php

namespace App\Models;

use App\Traits\UUID;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionPlan extends Model
{
    use HasFactory;
    use \Awobaz\Compoships\Compoships;

    const STATUS_PENDING = 0;
    const STATUS_IN_PROGRESS = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_PAUSED = 3;
    const STATUS_CANCELLED = 4;

    protected $table = 'production_plans';
    protected $fillable = [
        'id',
        'line_id',
        'ngay_dat_hang',
        'cong_doan_sx',
        'ca_sx',
        'ngay_sx',
        'ngay_giao_hang',
        'machine_id',
        'product_id',
        'khach_hang',
        'lo_sx',
        'so_bat',
        'sl_nvl',
        'sl_thanh_pham',
        'thu_tu_uu_tien',
        'note',
        'UPH',
        'nhan_luc',
        'tong_tg_thuc_hien',
        'thoi_gian_bat_dau',
        'thoi_gian_ket_thuc',
        'so_may_can_sx',
        'file',
        'nvl_da_cap',
        'status',
        'kho_giay',
        'toc_do',
        'thoi_gian_chinh_may',
        'thoi_gian_thuc_hien',
        'sl_giao_sx',
        'sl_tong_don_hang',
        'material_id',
        'status_plan',
        'uid'
    ];
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uid = $this->generateUniqueId($model->lo_sx, $model->machine_id);
        });
    }

    public static function generateUniqueId($lo_sx, $machine_id)
    {
        $latestPlan = ProductionPlan::where('lo_sx', $lo_sx)->where('machine_id', $machine_id)->get()->latest();
        $prefix = $lo_sx . '.' . $machine_id . '.L.';
        try {
            $index = $latestPlan ? (int) end(explode('.', $latestPlan->uid)) : 0;
        } catch (\Throwable $th) {
            $index = 0;
        }
        $newSequence = str_pad($index + 1, 4, '0', STR_PAD_LEFT);

        return $prefix . $newSequence;
    }
    // public function setThoiGianBatDauAttribute($value)
    // {
    //     $this->attributes['thoi_gian_bat_dau'] = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh');
    // }
    // public function setThoiGianKetThucAttribute($value)
    // {
    //     $this->attributes['thoi_gian_ket_thuc'] = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh');
    // }
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function loSX()
    {
        return $this->hasMany(Lot::class, "lo_sx", "lo_sx");
    }
    public function line()
    {
        return $this->belongsTo(Line::class);
    }
    public function material()
    {
        return $this->belongsTo(Material::class);
    }
    public function lotPlan()
    {
        return $this->hasMany(LotPlan::class, 'production_plan_id');
    }

    public function infoCongDoan(){
        return $this->hasOne(InfoCongDoan::class, 'plan_uid', 'uid');
    }
}
