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
        'product_order_id',
        'product_id',
        'order_quantity',
        'status',
        'delivery_date',
    ];
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public static function boot()
    {
        parent::boot();

        // static::creating(function ($model) {
        //     $model->id = self::generateUniqueId();
        // });
    }

    // public static function generateUniqueId()
    // {
    //     $currentMonth = Carbon::now()->format('ym');
    //     $latestLosx = self::where('id', 'LIKE', $currentMonth . '%')
    //         ->orderBy('id', 'desc')
    //         ->first();

    //     if ($latestLosx) {
    //         $lastSequence = (int) substr($latestLosx->id, -4);
    //     } else {
    //         $lastSequence = 0;
    //     }

    //     $newSequence = str_pad($lastSequence + 1, 4, '0', STR_PAD_LEFT);

    //     return $currentMonth . $newSequence;
    // }

    public static function generateUniqueId($productId)
    {
        $currentDate = Carbon::now()->format('ym');
        $prefix = $productId . '-' . $currentDate;
        $latestOrder = self::where('id', 'LIKE', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();
        if ($latestOrder) {
            $lastSequence = (int) substr($latestOrder->id, -2);
        } else {
            $lastSequence = 0;
        }
        $newSequence = str_pad($lastSequence + 1, 2, '0', STR_PAD_LEFT);
        return $prefix . $newSequence;
    }

    public static function generateUniqueIdPreview($index)
    {
        $currentMonth = Carbon::now()->format('ym');
        $latestLosx = self::where('id', 'LIKE', $currentMonth . '%')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestLosx) {
            $lastSequence = (int) substr($latestLosx->id, -4);
        } else {
            $lastSequence = 0;
        }

        $newSequence = str_pad($lastSequence + $index + 1, 4, '0', STR_PAD_LEFT);

        return $currentMonth . $newSequence;
    }
}
