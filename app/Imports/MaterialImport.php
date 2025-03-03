<?php

namespace App\Imports;

use App\Models\Material;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStartRow;

class MaterialImport implements ToCollection, WithHeadingRow, WithStartRow, WithCalculatedFormulas, WithMultipleSheets
{
    protected $fields;
    protected $material = null;

    public function __construct()
    {
        Log::debug('ProductImport __construct called');
    }

    public function sheets(): array
    {
        return [
            0 => $this, // Only process the first sheet
        ];
    }

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

    function importRow(array $row)
    {
        if (empty($row['id'])) {
            return;
        }
        try {
            $material = Material::find($row['id']);
            if (!$material) {
                $material = new Material();
                $material->id = $row['id'];
                $material->name = $row['name'];
                $material->material = $row['material'];
                $material->color = $row['color'];
                $material->quantitative = $row['quantitative'];
                $material->thickness = $row['thickness'];
                $material->meter_per_roll = $row['meter_per_roll'];
                $material->sheet_per_pallet = $row['sheet_per_pallet'];
                $material->save();
            }
        } catch (Exception $e) {
            throw $e->getMessage();
            Log::error($e->getMessage());
        }
    }
}
