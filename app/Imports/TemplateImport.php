<?php

namespace App\Imports;

use App\Helpers\QueryHelper;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
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
        Template::query()->delete();
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
        // $template = Template::query()->where('material_id', $material->id)->first();
        Template::create([
            'material_id' => $material->id,
            'quantity' => $quantity,
            'roll_quantity' => $roll_quantity,
            'manufacture_date' => $manufacture_date,
            'machine_number' => $machine_number,
            'worker_name' => $worker_name,
        ]);
        // if (empty($template)) {
        // } else {
        //     $template->quantity = $quantity;
        //     $template->roll_quantity = $roll_quantity;
        //     $template->manufacture_date = $manufacture_date;
        //     $template->machine_number = $machine_number;
        //     $template->worker_name = $worker_name;
        //     $template->save();
        // }

        // Lưu tồn và lịch sử nhập NVL
        $inventory = WarehouseInventory::where('material_id', $material->id)->first();
        if (empty($inventory)) {
            WarehouseInventory::create([
                'material_id' => $material->id,
                'quantity' => $quantity,
                'roll_quantity' => $roll_quantity,
            ]);
        } else {
            $inventory->quantity += $quantity;
            $inventory->roll_quantity += $roll_quantity;
            $inventory->save();
        }
        WarehouseHistories::create([
            'type' => WarehouseHistories::TYPE_IMPORT,
            'material_id' => $material->id,
            'quantity' => $quantity,
            'roll_quantity' => $roll_quantity,
        ]);

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
