<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class MachinePriorityOrderAttribute extends Model
{
    use HasFactory;

    protected $fillable = ['id', 'name', 'slug'];

    public function attributeValues()
    {
        return $this->hasMany(MachinePriorityOrderAttributeValue::class);
    }
}
