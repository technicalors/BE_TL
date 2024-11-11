<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Monitor extends Model
{
    public $incrementing = false;
    protected $fillable = ['id', 'content', 'description', 'machine_id', 'status', 'value', 'troubleshoot', 'created_by'];

    public function machine()
    {
        return $this->hasOne(Machine::class, 'id', 'machine_id');
    }
}
