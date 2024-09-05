<?php

namespace App\Imports;

use App\Helpers\QueryHelper;
use App\Models\Customer;
use App\Models\NumberMachineOrder;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\Spec;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class ProductOrderImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $fields;
    protected $user_id;

    public function __construct($user_id = null) {
        $this->user_id = $user_id;
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

    protected function importRow(array $row)
    {
        $orderNumber = $row['order_number'] ?? null;
        $orderDate = $row['order_date'] ?? null;
        $deliveryDate = $row['delivery_date'] ?? null;
        $customerId = $row['customer_id'] ?? null;
        $productId = $row['product_id'] ?? null;
        $quantity = $row['quantity'] ?? null;
        $note = $row['note'] ?? null;
        if (!$orderDate || !$productId || !$quantity) return;

        $product = Product::find($productId);
        if (empty($product)) return;

        $customer = Customer::find($customerId);
        if (empty($customer)) return;

        $orderDate = $this->transformDate($orderDate);
        if (!empty($deliveryDate)) $deliveryDate = $this->transformDate($deliveryDate);

        $id = QueryHelper::generateNewId(new ProductOrder(), date('Ym'), 2);

        // Create product_order
        $productOrder = ProductOrder::create([
            'id' => $id,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'order_date' => $orderDate,
            'quantity' => $quantity,
            'delivery_date' => $deliveryDate,
            'note' => $note,
        ]);
        $spec = Spec::with('line')->where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            ->orderBy('value', 'asc')
            ->groupBy('line_id')
            ->get()->filter(function ($value) {
                return is_numeric($value->value);
            })->values();
        foreach ($spec as $key => $value) {
            NumberMachineOrder::updateOrCreate([
                'product_order_id' => $productOrder->id,
                'line_id' => $value['line_id'],
                'number_machine' => 1,
                'user_id' => $this->user_id,
            ]);
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
