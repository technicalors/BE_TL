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
    protected $maxId = 0;
    // Hàm này xác định hàng bắt đầu lấy tiêu đề (heading row)
    public function headingRow(): int
    {
        return 2;
    }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 5;
    }
    public function collection(Collection $collection)
    {
        $this->fields = $collection->toArray();
        $this->maxId = 1;
        $error = ErrorMachine::orderBy('code', 'DESC')->first();
        $max = explode('-', $error->code)[1];
        if (empty($this->maxId)) throw new Exception('MaxId model not found');
        foreach ($collection as $row) {
            $this->maxId++;
            $this->importRow($this->maxId, $row->toArray(), $max);
        }
    }

    protected function importRow($id, array $row, $max)
    {
        $line_name = $row['line_name'] ?? null;
        $noi_dung = $row['noi_dung'] ?? null;
        $code = 'ml-'.($max + $id) ?? null;
        $nguyen_nhan = $row['nguyen_nhan'] ?? null;
        $khac_phuc = $row['khac_phuc'] ?? null;
        $phong_ngua = $row['phong_ngua'] ?? null;

        if (empty($line_name) || empty($code)) return;

        $line = Line::query()->whereRaw('UPPER(name) LIKE ?', [strtoupper($line_name)])->first();
        if (empty($line)) throw new Exception('Không tìm thấy công đoạn(line)');

        // Create product_order
        ErrorMachine::create([
            // 'id' => $id,
            'line_id' => $line->id,
            'noi_dung' => $noi_dung,
            'code' => Str::slug($code),
            'nguyen_nhan' => $nguyen_nhan,
            'khac_phuc' => $khac_phuc,
            'phong_ngua' => $phong_ngua,
        ]);
    }

    protected function transformDate($value)
    {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
    }
}
