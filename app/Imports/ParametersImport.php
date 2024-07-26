<?php

namespace App\Imports;

use App\Models\Parameter;
use App\Models\MachineParameter;
use App\Models\Parameters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class ParametersImport implements ToCollection, WithHeadingRow, WithStartRow
{
    public function headingRow(): int
    {
        return 2;
    }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 3;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            Log::info($row);
            // Lưu dữ liệu vào bảng parameters
            $parameter = Parameters::firstOrCreate(['id' => $row['id']], [
                'id' => $row['id'],
                'name' => $row['name'],
            ]);

            if($parameter){
                // Lưu dữ liệu vào bảng machine_parameters
                MachineParameter::firstOrCreate([
                    'machine_id' => $row['machine_id'],
                    'parameter_id' => $parameter->id,
                    'is_if' => 1
                ]);
            }
        }
    }
}
