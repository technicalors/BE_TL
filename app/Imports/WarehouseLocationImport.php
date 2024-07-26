<?php

namespace App\Imports;

use App\Models\Cell;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\UserInfo;
use App\Models\WareHouse;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class WarehouseLocationImport implements ToCollection, WithHeadingRow, WithStartRow
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
        $firstRow = $collection->first();
        if (empty($firstRow['warehouse_id'])) throw new Exception('Không tìm thấy ID kho ở file upload');

        $warehouse = WareHouse::find($firstRow['warehouse_id']); // warehouse_id char(36)
        if (empty($warehouse)) {
            if (empty($firstRow['warehouse_name'])) throw new Exception('Không tìm thấy tên kho ở file upload');
            $warehouse = new WareHouse();
            $warehouse->id = $firstRow['warehouse_id'];
            $warehouse->name = $firstRow['warehouse_name'];
            if(!$warehouse->save()) throw new Exception('Thao tác tạo kho thất bại');
        } elseif (!empty($firstRow['warehouse_name'])) {
            $warehouse->name = $firstRow['warehouse_name'];
            $warehouse->save();
        }
        if (empty($warehouse->id)) throw new Exception('Không tìm thấy ID kho');
        foreach ($collection as $row) {
            $this->importRow($warehouse->id, $row->toArray());
        }
    }

    protected function importRow($warehouse_id, array $row)
    {
        $location_id = $row['location_id'] ?? null;
        $location_name = $row['location_name'] ?? null;
        $sheft_id = $row['sheft_id'] ?? null;
        $number_of_bin = $row['number_of_bin'] ?? null;
        $product_id = $row['product_id'] ?? null;

        if (empty($location_id) || empty($location_name)) return;

        $location = Cell::find($location_id);
        $input = [];
        $input['warehouse_id'] = $warehouse_id;
        $input['name'] = $location_name;
        if (isset($sheft_id)) $input['sheft_id'] = $sheft_id;
        if (isset($number_of_bin)) $input['number_of_bin'] = $number_of_bin;
        if (isset($product_id)) $input['product_id'] = $product_id;
        
        if (empty($location)) {
            $input['id'] = $location_id;
            Cell::create($input);
        } else {
            $location->update($input);
        }
    }

    protected function transformDate($value)
    {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
    }
}
