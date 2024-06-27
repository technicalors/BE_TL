<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WareHouseExportPlan extends Model
{
    use HasFactory;
    protected $table = 'warehouse_export_plan';
    protected $fillable = ['khach_hang', 'ngay_xuat_hang', 'product_id', 'ten_san_pham','po_pending','sl_yeu_cau_giao','dvt',
                           'tong_kg','quy_cach','sl_thung_chan','sl_hang_le','ton_kho','xac_nhan_sx','sl_chenh_lech','sl_thuc_xuat',
                           'cua_xuat_hang','dia_chi','ghi_chu'];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
}
