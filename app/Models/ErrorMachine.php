<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class ErrorMachine extends Model
{
    use HasFactory;
    protected $table = "error_machine";
    protected $fillable = [
        'id',
        'line_id',
        'noi_dung',
        'code',
        'nguyen_nhan',
        'khac_phuc',
        'phong_ngua',
    ];
    protected $casts=[
        "id"=>"string"
    ];

    public function line(){
        return $this->belongsTo(Line::class, 'line_id');
    }
}
