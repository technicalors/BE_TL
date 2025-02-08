<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineShift extends Model
{
    use HasFactory;
    protected
        $table = 'machine_shift',
        $fillable = [
            'machine_id',
            'shift_id',
            'date',
            'ordering',
        ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
    public function shiftBreak()
    {
        return $this->hasMany(ShiftBreak::class, 'shift_id', 'shift_id');
    }
}
