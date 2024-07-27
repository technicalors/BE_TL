<?php

namespace App\Imports;

use App\Helpers\QueryHelper;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\Template;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class TemplateImport implements ToCollection, WithHeadingRow, WithStartRow
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
        $material_id = $row['material_id'] ?? null;
        $quantity = $row['quantity'] ?? 1;
        if (empty($material_id)) return;

        $material = Material::find($material_id);
        if (empty($material)) throw new Exception("Không tìm thấy NVL: $material_id");
        $template = Template::query()->where('material_id', $material->id)->first();
        if (empty($template)) {
            Template::create([
                'material_id' => $material->id,
                'quantity' => $quantity,
            ]);
        } else {
            $template->quantity = $quantity;
            $template->save();
        }
    }
}
