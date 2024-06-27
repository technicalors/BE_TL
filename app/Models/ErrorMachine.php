<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class ErrorMachine extends Model
{
    use HasFactory;
    protected $table = "error_machine";
    protected $casts=[
        "id"=>"string"
    ];

    public function line(){
        return $this->belongsTo(Line::class, 'line_id');
    }
}
