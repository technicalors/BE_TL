<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LotErrorLog extends Model
{
    use HasFactory;
    protected $fillable = ['lot_id', 'lo_sx', 'log', 'machine_code', 'line_id', 'user_id', 'created_at'];
    protected $casts = ['log' => 'json'];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}
