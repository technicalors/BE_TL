<?php

namespace App\Imports;

use App\Models\FcPlant;
use App\Models\FcPlantDetail;
use Carbon\Carbon;
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
        $columns = [];
        foreach ($rows->toArray() as $index => $row) {
            if (count($row) > 29) {
                if ($index > 0) {
                    $no = str_pad($key, 4, '0', STR_PAD_LEFT);
                    $details = [];
                    foreach ($columns as $index => $column) {
                        if (isset($row[$index + 7]) && isset($column['name'])) {
                            $details[$column['name']] = [
                                'value' => $row[$index + 7],
                                'date' => $column['date'] ?? null,
                            ];
                        }
                    }

                    $plant = $row[1] ?? null;
                    $plant_name = $row[2] ?? null;
                    $material = $row[3] ?? null;
                    $model = $row[4] ?? null;

                    $po = $row[5] ?? 0;
                    // if ($row[5] == null || $row[5] == '') {
                    //     $po = $poPreviousValue;
                    // } else {
                    //     $po = $row[5];
                    //     $poPreviousValue = $row[5];
                    // }
                    if (!isset($plant) || !isset($plant_name) || !isset($material) || !isset($model)) continue;

                    if (count(array_values($details)) > 0) {
                        $start_date = array_values($details)[0]['date'];
                        $end_date = array_values($details)[count(array_values($details)) - 1]['date'];
                    }

                    $main = FcPlant::create([
                        'code' => "{$time}_{$no}",
                        'plant' => $row[1] ?? null,
                        'plant_name' => $row[2] ?? null,
                        'material' => $row[3] ?? null,
                        'model' => $row[4] ?? null,
                        'po' => $po,
                        'sum_fc' => array_sum(array_map(function ($d) {
                            return $d['value'];
                        }, $details)),
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                    ]);

                    if (empty($main)) throw new Exception("Tạo FC thất bại ở dòng $key");
                    $data = [];
                    foreach ($details as $col => $detail) {
                        $data[] = [
                            'fc_plant_id' => $main->id,
                            'col' => $col,
                            'value' => $detail['value'],
                            'date' => $detail['date'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    FcPlantDetail::insert($data);
                    $this->imported++;
                    $key++;
                } elseif ($index == 0) {
                    foreach ($this->cols as $idx => $col) {
                        if (isset($row[$idx + 7])) {
                            $columns[] = [
                                'name' => trim(explode('(', $row[$idx + 7])[0]),
                                'date' => $this->extractDateFromString($row[$idx + 7]),
                            ];
                        }
                    }
                }
            }
        }

        if ($this->imported == 0) throw new Exception('Không có bản ghi nào được thêm');
    }

    private function extractDateFromString($rawDate)
    {
        $dateString = preg_replace('/\s+/', '', $rawDate);
        preg_match('/\((\d{2}\/\d{2})\)/', $dateString, $matches);

        if (isset($matches[1])) {
            return Carbon::createFromFormat('m/d/Y', $matches[1] . '/' . date('Y'));
        }

        return null;
    }
}
