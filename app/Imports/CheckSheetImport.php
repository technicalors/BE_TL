<?php

namespace App\Imports;

use App\Models\CheckSheet;
use App\Models\CheckSheetWork;
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

class CheckSheetImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $fields;
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
        foreach ($collection as $row) {
            $this->importRow($row->toArray());
        }
    }

    protected function importRow(array $row)
    {
        $line_name = $row['line_name'] ?? null;
        $hang_muc = $row['hang_muc'] ?? null;
        $cong_viec = $row['cong_viec'] ?? null;

        if (empty($line_name) || empty($hang_muc) || empty($cong_viec)) return;

        $line = Line::query()->whereRaw('UPPER(name) LIKE ?', [strtoupper($line_name)])->first();
        if (empty($line)) throw new Exception('Không tìm thấy công đoạn(line)');

        $hangMuc = CheckSheet::query()->whereRaw('UPPER(hang_muc) LIKE ?', [strtoupper($hang_muc)])->first();
        if (empty($hangMuc)) {
            $hangMuc = CheckSheet::create([
                'line_id' => $line->id,
                'hang_muc' => $hang_muc,
            ]);
        }

        CheckSheetWork::create(['check_sheet_id' => $hangMuc->id, 'cong_viec' => $cong_viec]);
    }

    protected function transformDate($value)
    {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
    }
}
