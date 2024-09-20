<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FcPlant extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'plant',
        'plant_name',
        'material',
        'model',
        'po',
        'sum_fc',
    ];

    /**
     * @return HasMany
     */
    public function details(): HasMany
    {
        return $this->hasMany(FcPlantDetail::class);
    }
}
