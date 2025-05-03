<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SelectionLineStamp extends Model
{
    use HasFactory;

    protected $fillable=['id', 'lo_sx', 'box_number', 'production_batch', 'lot_id', 'qr_code', 'selection_line_stamp_template_id', 'quantity', 'po_type', 'pack_quantity', 'plan_id'];

    public static function generateStampLotId(){
        $productionBatch = date('ymd');
        $latestStamp = SelectionLineStamp::where('production_batch', $productionBatch)
            ->orderBy('lot_id', 'desc')
            ->first();
        if ($latestStamp) {
            $lastSequence = (int) substr($latestStamp->lot_id, -4);
        } else {
            $lastSequence = 0;
        }

        $boxNumber = str_pad($lastSequence + 1, 4, '0', STR_PAD_LEFT);
        return [$productionBatch, $boxNumber];
    }

    public function template()
    {
        return $this->belongsTo(SelectionLineStampTemplate::class, 'selection_line_stamp_template_id');
    }
}
