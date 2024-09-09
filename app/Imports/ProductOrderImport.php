<?php

namespace App\Imports;

use App\Helpers\QueryHelper;
use App\Models\Customer;
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
        $orderNumber = $row['order_number'] ?? null;
        $orderDate = $row['order_date'] ?? null;
        $deliveryDate = $row['delivery_date'] ?? null;
        $customerId = $row['customer_id'] ?? null;
        $productId = $row['product_id'] ?? null;
        $quantity = $row['quantity'] ?? null;
        $note = $row['note'] ?? null;
        if (!$orderDate) {
            throw new \Exception("Chưa có ngày đặt hàng");
        }
        if (!$productId) {
            throw new \Exception("Chưa có mã sản phẩm");
        }
        if (!$quantity) {
            throw new \Exception("Chưa có số lượng");
        }
        $product = Product::find($productId);
        if (empty($product)) throw new \Exception("Mã khách hàng không tồn tại");

        $customer = Customer::find($customerId);
        if (empty($customer)) throw new \Exception("Mã khách hàng không tồn tại");

        $orderDate = $this->transformDate($orderDate);
        if (!empty($deliveryDate)) {
            $deliveryDate = $this->transformDate($deliveryDate);
        } else {
            $deliveryDate = date('Y-m-d', strtotime($orderDate . ' + 7 days'));
        }
        $id = QueryHelper::generateNewId(new ProductOrder(), date('Ym'), 2);
        // Create product_order
        ProductOrder::create([
            'id' => $id,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
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
