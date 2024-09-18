<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class MachinePriorityOrderAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = ['machine_priority_order_id', 'machine_priority_order_attribute_id', 'value'];

    public function attribute()
    {
        return $this->belongsTo(MachinePriorityOrderAttribute::class, 'machine_priority_order_attribute_id');
    }
}
