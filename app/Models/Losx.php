<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Losx extends Model
{
    use HasFactory;
    const STATUS_PENDING = 0;
    const STATUS_IN_PROGRESS = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_CANCELLED = 3;
    protected $table = 'losx';
    protected $fillable = [
        'id',
        'product_order_id',
        'product_id',
        'order_quantity',
        'status',
        'delivery_date',
        'priority',
    ];
    public $incrementing = false;
    protected $casts = [
        "id" => "string"
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
    
    public function productionOrderHistory()
    {
        return $this->hasMany(ProductionOrderHistory::class, 'lo_sx', 'id');
    }



    public static function generateUniqueIdV1()
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

        $newSequence = str_pad($lastSequence + 1, 4, '0', STR_PAD_LEFT);

        return $currentMonth . $newSequence;
    }

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
