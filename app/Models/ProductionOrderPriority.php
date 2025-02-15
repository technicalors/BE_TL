<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderPriority extends Model
{
    use HasFactory;
    protected $table = 'production_order_histories';
    protected $fillable = ['production_order_id', 'confirm_date', 'product_id', 'priority'];
}
