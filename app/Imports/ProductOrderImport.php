<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class ProductOrderImport implements ToCollection, WithHeadingRow, WithStartRow
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
        $orderDate = $row['order_date'] ?? null;
        $deliveryDate = $row['delivery_date'] ?? null;
        $productId = $row['product_id'] ?? null;
        $quantity = $row['quantity'] ?? null;
        $note = $row['note'] ?? null;
        if (!$orderDate || !$productId || !$quantity) return;

        $product = Product::find($productId);
        if (empty($product)) return;

        $orderDate = $this->transformDate($orderDate);
        if (!empty($deliveryDate)) $deliveryDate = $this->transformDate($deliveryDate);

        // Create product_order
        ProductOrder::create([
            'product_id' => $productId,
            'order_date' => $orderDate,
            'quantity' => $quantity,
            'delivery_date' => $deliveryDate,
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
