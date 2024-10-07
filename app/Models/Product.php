<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Product extends Model
{
    use HasFactory;
    protected $fillable = ['id', 'name', 'so_bat', 'material_id', 'dinh_muc', 'customer_id', 'ver', 'his', 'weight', 'paper_norm', 'nhiet_do_phong', 'do_am_phong', 'do_am_giay', 'thoi_gian_bao_on', 'chieu_dai_thung', 'chieu_rong_thung', 'chieu_cao_thung', 'the_tich_thung', 'dinh_muc_thung', 'u_nhiet_do_phong', 'u_do_am_phong', 'u_do_am_giay', 'u_thoi_gian_u', 'number_of_bin', 'kt_kho_dai', 'kt_kho_rong'];
    protected $hidden = ['created_at', 'updated_at'];
    protected $casts = ['info' => 'json', "id" => "string"];
    public $incrementing = false;

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function materialLog()
    {
        return $this->hasOne(MaterialLog::class, 'material_id');
    }

    public function machinespec()
    {
        return $this->hasOne(MachineSpec::class, 'product_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function materials()
    {
        return $this->belongsToMany(Material::class, 'bom', 'product_id', 'material_id')
            ->withPivot('priority')
            ->orderBy('priority', 'asc');
    }

    public function spec()
    {
        return $this->hasMany(Spec::class);
    }

    public function warehouseLog()
    {
        return $this->hasManyThrough(WareHouseLog::class, Lot::class, 'product_id', 'lot_id', 'id', 'id');
    }

    public function lots()
    {
        return $this->hasMany(Lot::class);
    }

    static function validate($input, $id = null)
    {
        $validated = Validator::make(
            $input,
            [
                'id' => 'required|unique:products,id' . ($id ? ',' . $id : ""),
                'name' => 'required',
                'customer_id' => 'required',
            ],
            [
                'id.required' => 'Không tìm thấy mã sản phẩm',
                'id.unique' => 'Mã sản phẩm đã tồn tại',
                'name.required' => 'Không tìm thấy tên sản phẩm',
                'customer_id.required' => 'Không có khách hàng',
            ]
        );
        return $validated;
    }

    public function materialWastages()
    {
        return $this->hasMany(MaterialWastage::class);
    }

    public function timeWastages()
    {
        return $this->hasMany(TimeWastage::class);
    }

    public function boms()
    {
        return $this->hasMany(Bom::class);
    }

    public function specs()
    {
        return $this->hasMany(Spec::class);
    }

    public function machinePriorityOrders()
    {
        return $this->hasMany(MachinePriorityOrder::class);
    }
}
