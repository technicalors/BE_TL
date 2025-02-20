<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class ProductionJourney extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','line_id','production_order', 'material_waste', 'line_production_waste', 'prep_time', 'transportation_waste', 'roll_change_time', 'input_quantity', 'hourly_output', 'operator_count'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }
}
