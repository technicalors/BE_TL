<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\UserInfo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class UserInfoImport implements ToCollection, WithHeadingRow, WithStartRow
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
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $dateJoinCompany = $row['date_join_company'] ?? null;
        $dateEndTrial = $row['date_end_trial'] ?? null;
        $category1 = $row['category1'] ?? null;
        $category2 = $row['category2'] ?? null;
        $category3 = $row['category3'] ?? null;
        $category4 = $row['category4'] ?? null;
        $category5 = $row['category5'] ?? null;
        $note = $row['note'] ?? null;

        if (!$id || !$name || !$dateJoinCompany) return;

        $dateJoinCompany = $this->transformDate($dateJoinCompany);
        if (!empty($dateEndTrial)) $dateEndTrial = $this->transformDate($dateEndTrial);

        // Create product_order
        UserInfo::create([
            'id' => $id,
            'name' => $name,
            'date_join_company' => $dateJoinCompany,
            'date_end_trial' => $dateEndTrial,
            'category1' => $category1,
            'category2' => $category2,
            'category3' => $category3,
            'category4' => $category4,
            'category5' => $category5,
            'note' => $note,
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
