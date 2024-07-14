<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_schedule_id',
        'log_date',
        'remark',
        'result'
    ];

    public function maintenanceLogImages()
    {
        return $this->hasMany(MaintenanceLogImage::class, 'maintenance_log_id');
    }
}
