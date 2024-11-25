<?php

namespace App\Imports;

use App\Helpers\QueryHelper;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\RollMaterial;
use App\Models\Template;
use App\Models\Unit;
use App\Models\WarehouseHistories;
use App\Models\WarehouseInventory;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class TemplateImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected int $imported = 0;

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
        // Template::query()->delete();
        Template::query()->update(['status' => 1]);
        foreach ($collection as $row) {
            $this->importRow($row->toArray());
        }

        if ($this->imported == 0) throw new Exception('Không có bản ghi nào được thêm');
    }

    protected function importRow(array $row)
    {
        $material_id = $row['material_id'] ?? null;
        $quantity = $row['quantity'] ?? 1;
        $roll_quantity = $row['roll_quantity'] ?? 0;
        $manufacture_date = $row['manufacture_date'] ?? null;
        $machine_number = $row['machine_number'] ?? null;
        $worker_name = $row['worker_name'] ?? null;
        if (empty($material_id)) return;

        if (!empty($manufacture_date)) {
            $manufacture_date = $this->transformDate($manufacture_date);
        }

        $material = Material::find($material_id);
        if (empty($material)) throw new Exception("Không tìm thấy NVL: $material_id");
        for ($i=0; $i<$roll_quantity; $i++) {
            $template = Template::create([
                'material_id' => $material->id,
                'quantity' => $quantity, // 👈 Số lượng NVL của 1 cuộn
                'roll_quantity' => 1,
                'manufacture_date' => $manufacture_date,
                'machine_number' => $machine_number,
                'worker_name' => $worker_name,
                'status' => 0,
            ]);
    
            // Lưu roll
            $prefix = 'C' . date('dmy');
            $roll_id = QueryHelper::generateNewId(new RollMaterial(), $prefix, 3);
            RollMaterial::create([
                'id' => $roll_id,
                'template_id' => $template->id,
                'material_id' => $material->id,
                'quantity' => $quantity, // 👈 Số lượng NVL của 1 cuộn
                'roll_quantity' => 1,
            ]);

            // Lưu tồn và lịch sử nhập NVL
            WarehouseInventory::create([
                'roll_id' => $roll_id,
                'material_id' => $material->id,
                'quantity' => $quantity, // Tổng số lượng NVL
                'roll_quantity' => 1,
            ]);
            WarehouseHistories::create([
                'type' => WarehouseHistories::TYPE_IMPORT,
                'roll_id' => $roll_id,
                'material_id' => $material->id,
                'quantity' => $quantity, // Tổng số lượng NVL
                'roll_quantity' => 1,
            ]);
        }

        $this->imported++;
    }

    protected function transformDate($value)
    {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
    }
}
