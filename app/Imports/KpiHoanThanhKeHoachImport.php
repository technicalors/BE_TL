<?php

namespace App\Imports;

use App\Models\KpiHoanThanhKeHoach;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class KpiHoanThanhKeHoachImport implements ToCollection, WithStartRow
{
    protected int $imported = 0;

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $ngayRaw = $row[0] ?? null;
            $tiLeRaw = $row[1] ?? null;

            if ($ngayRaw === null || $ngayRaw === '' || $tiLeRaw === null || $tiLeRaw === '') {
                continue;
            }

            $ngay = $this->transformDate($ngayRaw, $index + 2);

            if (!is_numeric($tiLeRaw)) {
                throw new Exception('Tỷ lệ không hợp lệ tại dòng ' . ($index + 2));
            }

            $tiLe = round((float) $tiLeRaw, 2);
            if ($tiLe < 0 || $tiLe > 100) {
                throw new Exception('Tỷ lệ phải trong khoảng 0-100 tại dòng ' . ($index + 2));
            }

            KpiHoanThanhKeHoach::updateOrCreate(
                ['ngay' => $ngay],
                ['ti_le' => $tiLe]
            );

            $this->imported++;
        }

        if ($this->imported === 0) {
            throw new Exception('Không có bản ghi hợp lệ để cập nhật');
        }
    }

    protected function transformDate($value, int $line): string
    {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->format('Y-m-d');
        }

        if (is_string($value)) {
            $value = trim($value);

            try {
                return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
            } catch (Exception $e) {
            }

            try {
                return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
            } catch (Exception $e) {
            }
        }

        throw new Exception('Ngày không hợp lệ tại dòng ' . $line);
    }
}
