<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LotErrorLog extends Model
{
    use HasFactory;
    protected $fillable = ['lot_id', 'log', 'machine_code', 'line_id', 'user_id'];
    protected $casts = ['log' => 'json'];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}
