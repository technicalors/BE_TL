<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LSXLog extends Model
{
    use HasFactory;
    protected $fillable = ['lsx', 'info'];
    protected $casts = ['info' => 'json'];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public static function listPallet($line)
    {
        $now  = date('Y-m-d', strtotime('-4 day'));
        $list =  LSXLog::whereDate('updated_at', ">=", $now)->whereNotNull('info->qc->' . $line . '->thoi_gian_vao')->orderBy('info->qc->' . $line . '->thoi_gian_vao', 'DESC');
        return $list;
    }

    // public function checkQC($line_key, $plan = null)
    // {
    //     if($line_key === 'oqc'){
    //         return true;
    //     }
    //     if ($line_key !== 'gap-dan') {
    //         if($line_key === 'chon'){
    //             $count = 0;
    //             if (isset($this->info['qc'][$line_key])) {
    //                 foreach (($this->info['qc'][$line_key] ?? []) as $key => $check) {
    //                     if (in_array($key, ['kich-thuoc', 'ngoai-quan'])) {
    //                         if ($check && isset($check['result']) && $check['result']) {
    //                             $count += 1;
    //                         } else {
    //                             return false;
    //                         }
    //                     } else {
    //                         continue;
    //                     }
    //                 }
    //             } else {
    //                 return false;
    //             }
    //             if ($count < 2) return false;
    //         }else{
    //             $count = 0;
    //             if (isset($this->info['qc'][$line_key])) {
    //                 foreach (($this->info['qc'][$line_key] ?? []) as $key => $check) {
    //                     if (in_array($key, ['dac-tinh', 'kich-thuoc', 'ngoai-quan'])) {
    //                         if ($check && isset($check['result']) && $check['result']) {
    //                             $count += 1;
    //                         } else {
    //                             return false;
    //                         }
    //                     } else {
    //                         continue;
    //                     }
    //                 }
    //             } else {
    //                 return false;
    //             }
    //             if ($count < 3) return false;
    //         }
    //     } else {
    //         if (isset($this->info['qc'][$line_key]['bat'])) {
    //             $bats = $this->info['qc'][$line_key]['bat'];
    //             $bat = end($bats);
    //             $count = 0;
    //             foreach ($bat as $key => $check) {
    //                 if (in_array($key, ['dac-tinh', 'kich-thuoc', 'ngoai-quan'])) {
    //                     if ($check && isset($check['result']) && $check['result']) {
    //                         $count += 1;
    //                     } else {
    //                         return false;
    //                     }
    //                 } else {
    //                     continue;
    //                 }
    //             }
    //             if ($count < 3) return false;
    //         } else {
    //             return false;
    //         }
    //     }
    //     return true;
    // }

    public function checkQC($line_key)
    {
        $qc_data = [];
        if (!isset($this->info['qc'][$line_key])) {
            return false;
        } else {
            $qc_data = $this->info['qc'][$line_key];
        }
        $result = array_column(array_intersect_key($qc_data, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
        if (in_array(0, $result)) {
            return false;
        }
        if ($line_key === 'gap-dan') {
            if (!isset($this->info['qc']['gap-dan']['bat'])) {
                return false;
            }
            $bats = $this->info['qc']['gap-dan']['bat'];
            $bat = Lot::where('p_id', $this->lot->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
            $qc_bat = [];
            if ($bat && isset($bats[$bat->id])) {
                $qc_bat = $bats[$bat->id];
            }
            $result_bat = array_column(array_intersect_key($qc_bat, array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
            if (in_array(0, $result_bat) || count($result_bat) !== 3) {
                return false;
            }
        } else {
            switch ($line_key) {
                case 'oqc':
                    if (count($result) !== 1) {
                        return false;
                    }
                    break;
                case 'chon':
                    if (count($result) !== 2) {
                        return false;
                    }
                    break;
                default:
                    if (count($result) !== 3) {
                        return false;
                    }
                    break;
            }
        }
        return true;
    }


    public static function overrallIn($line_id)
    {
        $list =  ProductionPlan::whereDate('ngay_sx', Carbon::today())->get();

        $tong_sl_kh_ngay = 0;
        foreach ($list as $item) {
            $tong_sl_kh_ngay += $item->so_bat * $item->sl_thanh_pham;
        }
        $tong_sl_thuc_te = 0;
        $tong_sl_ng = 0;
        $tong_sl_tem_vang = 0;
        $info_cong_doans = InfoCongDoan::where('line_id', $line_id)->whereDate("created_at", Carbon::now())->get();
        foreach ($info_cong_doans as $key => $info_congdoan) {
            $lot = Lot::find($info_congdoan->lot_id);
            if ($line_id == 10 || $line_id == 11 || $line_id == 12) {
                $tong_sl_thuc_te += $info_congdoan->sl_dau_ra_hang_loat / $lot->product->so_bat;
                $tong_sl_ng += $info_congdoan->sl_ng / $lot->product->so_bat;
                $tong_sl_tem_vang += $info_congdoan->sl_tem_vang / $lot->product->so_bat;
            } else {
                $tong_sl_thuc_te += $info_congdoan->sl_dau_ra_hang_loat;
                $tong_sl_ng += $info_congdoan->sl_ng;
                $tong_sl_tem_vang += $info_congdoan->sl_tem_vang;
            }
        }

        return $data =  [
            "tong_sl_trong_ngay_kh" => $tong_sl_kh_ngay,
            "tong_sl_thuc_te" =>  $tong_sl_thuc_te,
            "tong_sl_tem_vang" =>  $tong_sl_tem_vang,
            "tong_sl_ng" => $tong_sl_ng,
        ];
    }
}
