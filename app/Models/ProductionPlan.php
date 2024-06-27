<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionPlan extends Model
{
    use HasFactory;
    use \Awobaz\Compoships\Compoships;

    protected $table = 'production_plans';
    protected $fillable = ['id','line_id' ,'ngay_dat_hang', 'cong_doan_sx', 'ca_sx', 'ngay_sx','ngay_giao_hang','machine_id','product_id','khach_hang','lo_sx','so_bat','sl_nvl',
                            'sl_thanh_pham','thu_tu_uu_tien','note','UPH','nhan_luc','tong_tg_thuc_hien','thoi_gian_bat_dau','thoi_gian_ket_thuc','so_may_can_sx','file',
                            'nvl_da_cap','status','kho_giay','toc_do','thoi_gian_chinh_may','thoi_gian_thuc_hien','sl_giao_sx','sl_tong_don_hang'];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function loSX(){
        return $this->hasMany(Lot::class,"lo_sx","lo_sx");
    }
    public function line()
    {
        return $this->belongsTo(Line::class);
    }
}
