<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Losx extends Model
{
    use HasFactory;
    protected $table = 'losx';
    protected $fillable = [
        'product_order_id'
    ];
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->id = self::generateUniqueId();
        });
    }

    private static function generateUniqueId()
    {
        $currentMonth = Carbon::now()->format('ym');
        $latestLosx = self::where('id', 'LIKE', $currentMonth . '%')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestLosx) {
            $lastSequence = (int) substr($latestLosx->id, -3);
        } else {
            $lastSequence = 0;
        }

        $newSequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);

        return $currentMonth . $newSequence;
    }
}
