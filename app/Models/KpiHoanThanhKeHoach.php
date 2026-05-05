<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiHoanThanhKeHoach extends Model
{
    use HasFactory;

    protected $table = 'kpi_hoan_thanh_ke_hoach';

    protected $fillable = [
        'ngay',
        'ti_le',
    ];

    protected $casts = [
        'ngay' => 'date',
    ];
}
