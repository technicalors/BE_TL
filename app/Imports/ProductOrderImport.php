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

use function GuzzleHttp\json_encode;

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
        $customerId = trim($row['customer_id'] ?? "") ?? null;
        $productId = trim($row['product_id'] ?? "") ?? null;
        $quantity = $row['quantity'] ?? null;
        $note = $row['note'] ?? null;
        $productName = $row['product_name'] ?? null;
        if (!$productId) {
            throw new \Exception("Chưa có mã sản phẩm");
        }
        if (!$quantity) {
            throw new \Exception("Chưa có số lượng");
        }
        $product = Product::find($productId);
        if (empty($product)) throw new \Exception("Mã mã hàng ".$productId." không tồn tại");

        $customer = Customer::find($customerId);
        if (empty($customer)) throw new \Exception("Mã khách hàng ".$customerId." không tồn tại");
        if(empty($orderDate)){
            $orderDate = Carbon::parse('now');
        }else{
            $orderDate = $this->transformDate($orderDate);
        }
        // if (!empty($deliveryDate)) {
        //     $deliveryDate = $this->transformDate($deliveryDate);
        // } else {
        //     $deliveryDate = date('Y-m-d', strtotime($orderDate . ' + 7 days'));
        // }
        if(Carbon::now()->format('H:i:s') < '16:30:00'){
            $deliveryDate = date('Y-m-d', strtotime($orderDate . ' + 7 days'));
        }else{
            $deliveryDate = date('Y-m-d', strtotime($orderDate . ' + 6 days'));
        }

        $id = QueryHelper::generateNewId(new ProductOrder(), date('Ym'), 2);
        // Create product_order
        $productOrder = ProductOrder::create([
            'id' => $id,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'product_name'=>$productName,
            'order_date' => $orderDate,
            'quantity' => $quantity,
            'delivery_date' => $deliveryDate,
            'note' => $note,
            'fc_quantity' => 0,
            'sl_giao_sx' => $quantity
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
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
        }
        return Carbon::createFromFormat('d/m/Y', $value);
    }
}
