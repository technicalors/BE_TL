<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class Line extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'note', 'ordering', 'display', 'factory_id'];
    protected $hidden = ['created_at', 'updated_at'];

    public function machine()
    {
        return $this->hasMany(Machine::class, 'line_id');
    }
    public function checkSheet()
    {
        return $this->hasMany(CheckSheet::class, 'line_id');
    }
    public function children()
    {
        return $this->machine();
    }
    // Lỗi của máy
    public function error()
    {
        return $this->hasMany(ErrorMachine::class, 'line_id');
    }

    static function validateUpdate($input, $is_update = true)
    {
        $validated = Validator::make(
            $input,
            [
                'name' => 'required',
            ],
            [
                'name.required' => 'Không có tên công đoạn',
            ]
        );
        return $validated;
    }
    public function factory()
    {
        return $this->belongsTo(Factory::class);
    }
    public function testCriteria()
    {
        return $this->belongsToMany(TestCriteria::class, 'test_criteria_line', 'line_id', 'test_criteria_id');
    }
}
