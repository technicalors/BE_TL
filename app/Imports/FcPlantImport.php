<?php

namespace App\Imports;

use App\Models\FcPlant;
use App\Models\FcPlantColumn;
use App\Models\FcPlantDetail;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class FcPlantImport implements ToCollection, WithStartRow, WithCalculatedFormulas
{
    protected int $imported = 0;
    protected array $cols = ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC'];

    // public function headingRow(): int
    // {
    //     return 1;
    // }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 1;
    }

    public function collection(Collection $rows)
    {
        $time = date('ymdHis');
        $poPreviousValue = null;
        $key = 1;
        foreach ($rows->toArray() as $index => $row) {
            if (count($row) == 29) {
                if ($index > 0) {
                    $no = str_pad($key, 4, '0', STR_PAD_LEFT);
                    $details = [
                        'G'  => $row[6] ?? 0,
                        'H'  => $row[7] ?? 0,
                        'I'  => $row[8] ?? 0,
                        'J'  => $row[9] ?? 0,
                        'K'  => $row[10] ?? 0,
                        'L'  => $row[11] ?? 0,
                        'M'  => $row[12] ?? 0,
                        'N'  => $row[13] ?? 0,
                        'O'  => $row[14] ?? 0,
                        'P'  => $row[15] ?? 0,
                        'Q'  => $row[16] ?? 0,
                        'R'  => $row[17] ?? 0,
                        'S'  => $row[18] ?? 0,
                        'T'  => $row[19] ?? 0,
                        'U'  => $row[20] ?? 0,
                        'V'  => $row[21] ?? 0,
                        'W'  => $row[22] ?? 0,
                        'X'  => $row[23] ?? 0,
                        'Y'  => $row[24] ?? 0,
                        'Z'  => $row[25] ?? 0,
                        'AA' => $row[26] ?? 0,
                        'AB' => $row[27] ?? 0,
                        'AC' => $row[28] ?? 0,
                    ];
    
                    $plant = $row[0] ?? null;
                    $plant_name = $row[1] ?? null;
                    $material = $row[2] ?? null;
                    $model = $row[3] ?? null;
    
                    $po = null;
                    if ($row[4] == null || $row[4] == '') {
                        $po = $poPreviousValue;
                    } else {
                        $po = $row[4];
                        $poPreviousValue = $row[4];
                    }
    
                    if (!isset($plant) || !isset($plant_name) || !isset($material) || !isset($model) || !isset($po)) return;
    
                    $main = FcPlant::create([
                        'code' => "{$time}_{$no}",
                        'plant' => $row[0] ?? null,
                        'plant_name' => $row[1] ?? null,
                        'material' => $row[2] ?? null,
                        'model' => $row[3] ?? null,
                        'po' => $po,
                        'sum_fc' => array_sum($details),
                    ]);
    
                    if (empty($main)) throw new Exception("Tạo FC thất bại ở dòng $key");
                    $data = [];
                    foreach ($details as $col => $detail) {
                        $data[] = [
                            'fc_plant_id' => $main->id,
                            'col' => $col,
                            'value' => $detail,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    FcPlantDetail::insert($data);
                    $this->imported++;
                    $key++;
                } elseif ($index == 0) {
                    foreach ($this->cols as $idx => $col) {
                        if (isset($row[$idx + 6])) FcPlantColumn::create(['value' => $col, 'name' => $row[$idx + 6]]);
                    }
                }
            }
        }

        if ($this->imported == 0) throw new Exception('Không có bản ghi nào được thêm');
    }
}
