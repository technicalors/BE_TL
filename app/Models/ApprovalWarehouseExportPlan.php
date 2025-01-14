<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalWarehouseExportPlan extends Model
{
    use HasFactory;
    protected $table = 'approval_warehouse_export_plan';
    protected $fillable = [
        'warehouse_export_plan_id',
        'approver_id',
        'created_at',
        'updated_at',
    ];
}
