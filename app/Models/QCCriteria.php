<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class QCCriteria extends Model
{
    use HasFactory;
    protected $table = 'qc_criteria';
    protected $fillable = ['product_id','line_id','criteria_name', 'criteria_value'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function line()
    {
        return $this->belongsTo(Line::class, 'line_id');
    }
}
