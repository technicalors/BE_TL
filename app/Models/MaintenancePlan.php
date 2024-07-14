<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenancePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'start_day',
        'cycle_type',
        'cycle_interval',
    ];

    public function maintenanceSchedule()
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }
}
