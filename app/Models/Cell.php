<?php

namespace App\Models;

use App\Traits\IDTimestamp;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class Cell extends Model
{
    use HasFactory,UUID;
    protected $fillable = ['id','note','name','sheft_id','number_of_bin','product_id'];
    protected $hidden=['created_at','updated_at','id','sheft_id','note'];
    public function lot(){
        return $this->belongsToMany(Lot::class,'cell_lot','cell_id','lot_id');
    }
    public function sheft(){
        return $this->belongsTo(Sheft::class,'sheft_id');
    }

    
    

}
