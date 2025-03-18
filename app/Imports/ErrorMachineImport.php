<?php

namespace App\Imports;

use App\Models\ErrorMachine;
use App\Models\Line;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Str;

class ErrorMachineImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $fields;
    // Hàm này xác định hàng bắt đầu lấy tiêu đề (heading row)
    public function headingRow(): int
    {
        return 1;
    }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 3;
    }
    public function collection(Collection $collection)
    {
        $this->fields = $collection->toArray();
        foreach ($collection->toArray() as $row) {
            $this->importRow($row);
        }
    }

    protected function importRow(array $row)
    {
        $line_name = $row['line_name'] ?? null;

        try {
            $line_name = $row['line_name'] ?? null;
            if (empty($line_name) || empty($row['code'])) return;
    
            $line = Line::query()->whereRaw('UPPER(name) LIKE ?', [strtoupper($line_name)])->first();
            if (!$line) {
                Log::warning("Không tìm thấy công đoạn: {$line_name}");
                return; // Bỏ qua dòng lỗi, không dừng import
            }
    
            $error_machine = ErrorMachine::where('code', Str::slug($row['code']))->first();
            // Log::debug($error_machine);
            if($error_machine){
                $error_machine->update([
                    'type' => array_search(trim($row['type'] ?? ''), ErrorMachine::ERROR_TYPE) ?: null,
                    'line_id' => $line->id,
                    'noi_dung' => trim($row['noi_dung'] ?? ''),
                    'nguyen_nhan' => trim($row['nguyen_nhan'] ?? ''),
                    'khac_phuc' => trim($row['khac_phuc'] ?? ''),
                    'phong_ngua' => trim($row['phong_ngua'] ?? ''),
                ]);
                // Log::debug($error_machine);
            } else {
                $error_machine = ErrorMachine::create([
                    'code' => Str::slug($row['code']),
                    'type' => array_search(trim($row['type'] ?? ''), ErrorMachine::ERROR_TYPE) ?: null,
                    'line_id' => $line->id,
                    'noi_dung' => trim($row['noi_dung'] ?? ''),
                    'nguyen_nhan' => trim($row['nguyen_nhan'] ?? ''),
                    'khac_phuc' => trim($row['khac_phuc'] ?? ''),
                    'phong_ngua' => trim($row['phong_ngua'] ?? ''),
                ]);
                Log::debug($error_machine);
            }
            // ErrorMachine::updateOrCreate(
            //     ['code' => Str::slug($row['code'])],
            //     [
            //         'type' => array_search(trim($row['type'] ?? ''), ErrorMachine::ERROR_TYPE) ?: null,
            //         'line_id' => $line->id,
            //         'noi_dung' => trim($row['noi_dung'] ?? ''),
            //         'nguyen_nhan' => trim($row['nguyen_nhan'] ?? ''),
            //         'khac_phuc' => trim($row['khac_phuc'] ?? ''),
            //         'phong_ngua' => trim($row['phong_ngua'] ?? ''),
            //     ]
            // );
        } catch (Exception $e) {
            Log::error("Lỗi import: " . $e->getMessage());
        }
    }
}
