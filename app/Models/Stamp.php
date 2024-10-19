<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stamp extends Model
{
    use HasFactory;

    protected $fillable = [
        'lot_id',
        'ten_sp',
        'soluongtp',
        'ver',
        'his',
        'lsx',
        'cd_thuc_hien',
        'cd_tiep_theo',
        'nguoi_sx',
        'ghi_chu',
        'machine_code'
    ];
}
