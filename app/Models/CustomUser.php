<?php

namespace App\Models;

use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Middleware\Authenticate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Validator;

class CustomUser extends Model
{
    use HasApiTokens, Notifiable;
    protected $table = 'admin_users';
    protected $fillable = [
        'name', 'username', 'id', 'password'
    ];
    protected $guarded = [];

    public function permissions()
    {
        $pivotTable = config('admin.database.user_permissions_table');

        $relatedModel = config('admin.database.permissions_model');

        return $this->belongsToMany($relatedModel, $pivotTable, 'user_id', 'permission_id');
    }
    public function roles()
    {
        $pivotTable = config('admin.database.role_users_table');

        $relatedModel = config('admin.database.roles_model');

        return $this->belongsToMany($relatedModel, $pivotTable, 'user_id', 'role_id');
    }

    // public function rolePermissions(){
    //     return $this->hasManyThrough(Permission::class,Role::class,);
    // }




    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
        });
    }

    static function validateUpdate($input, $is_update = true)
    {
        $validated = Validator::make(
            $input,
            [
                'username' => 'required',
                'name'=>'required', 
            ],
            [
                'username.required'=>'Không tìm thấy tài khoản',
                'name.required'=>'Không có tên',
            ]
        );
        return $validated;
    }
}
