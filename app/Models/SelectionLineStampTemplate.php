<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelectionLineStampTemplate extends Model
{
    use HasFactory;

    protected $fillable=['id', 'product_id', 'vendor_name', 'part_no', 'vendor_code', 'po_type', 'box_quantity', 'specification', 'week'];
}
