<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_item_id',
        'machine_code',
        'maintenance_plan_id',
        'due_date',
    ];

    public function maintenanceItem()
    {
        return $this->belongsTo(MaintenanceItem::class);
    }

    public function maintenancePlan()
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_code', 'code');
    }

    public function maintenanceLog()
    {
        return $this->hasOne(MaintenanceLog::class);
    }
}
