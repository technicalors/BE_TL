<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LotPlan extends Model
{
    use HasFactory;
    use \Awobaz\Compoships\Compoships;

    protected $fillable = ['input_lot_id', 'lot_id', 'lo_sx', 'line_id', 'product_id', 'start_time', 'end_time', 'quantity', 'machine_code', 'product_order_id', 'customer_id', 'production_plan_id', 'lot_size', 'status'];
    public function setStartTimeAttribute($value)
    {
        $this->attributes['start_time'] = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh');
    }
    public function setEndTimeAttribute($value)
    {
        $this->attributes['end_time'] = Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh');
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
        return $this->belongsTo(ProductionPlan::class);
    }
    public function spec()
    {
        return $this->hasMany(Spec::class, ['product_id', 'line_id'], ['product_id', 'line_id']);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_code', 'code');
    }
    public function infoCongDoan()
    {
        return $this->hasOne(InfoCongDoan::class);
    }
}
