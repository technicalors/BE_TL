<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExcelHeader extends Model
{
    use HasFactory;
    const TABLE_LIST = [
        'products',
        'bom',
        'material'
    ];
    
    protected $fillable = ['header_name', 'column_position', 'section', 'parent_id', 'field_name'];

    public function children(){
        return $this->hasMany(self::class, 'parent_id');
    }
    public function parent(){
        return $this->belongsTo(self::class, 'parent_id');
    }
}
