<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceLogImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_log_id',
        'image_path',
    ];

    public function maintenanceLog()
    {
        return $this->belongsTo(MaintenanceLog::class);
    }
}
