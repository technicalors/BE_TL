<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelectionLineStampTemplate extends Model
{
    use HasFactory;

    protected $fillable=['id', 'vendor_name', 'part_no', 'vendor_code', 'po_type', 'box_number', 'production_batch', 'qr_code', 'specification', 'week'];
}
