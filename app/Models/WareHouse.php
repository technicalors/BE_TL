<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WareHouse extends Model
{
    use HasFactory;
    protected $hidden = ['created_at', 'updated_at', 'note'];
    protected $fillable = ['id', 'name', 'note'];
    protected $keyType = 'string';
    public $incrementing = false;
    public function sheft()
    {
        return $this->hasMany(Sheft::class, "warehouse_id");
    }
    public function cell()
    {
        return $this->hasManyThrough(Cell::class, Sheft::class,'warehouse_id');
    }
}
