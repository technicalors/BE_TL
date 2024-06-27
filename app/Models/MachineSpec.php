<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineSpec extends Model
{
    use HasFactory;
    protected $table = "machine_spec";
    protected $fillable = ['id', 'product_id', 'value'];
}
