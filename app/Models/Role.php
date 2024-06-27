<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Support\Facades\Validator;

class Role extends Model
{
    use HasFactory;
    protected $table = 'admin_roles';
    protected $fillable = ['id', 'name', 'slug'];

    public function permissions(){
        return $this->hasManyThrough(Permission::class, RolePermission::class, 'role_id', 'id', 'id', 'permission_id');
    }

    static function validateUpdate($input, $is_update = true)
    {
        $validated = Validator::make(
            $input,
            [
                'name' => 'required',
            ],
            [
                'name.required'=>'Không có tên bộ phận',
            ]
        );
        return $validated;
    }
}
