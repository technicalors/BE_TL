<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NumberMachineOrder extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_order_id',
        'number_machine',
        'line_id',
        'user_id',
    ];

    public function productOrder()
    {
        return $this->belongsTo(ProductOrder::class);
    }
}
