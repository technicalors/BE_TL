<?php

namespace App\Models;

use App\Traits\IDTimestamp;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class CellLot extends Model
{
    use HasFactory;
    protected $table="cell_lot";
    protected $fillable = ['id','cell_id','lot_id'];
    public function lot(){
        return $this->belongsTo(Lot::class,'lot_id');
    }
    public function cell(){
        return $this->belongsTo(Cell::class,'cell_id');
    }
}
