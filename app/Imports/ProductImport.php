<?php

namespace App\Imports;

use App\Helpers\QueryHelper;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\ExcelHeader;
use App\Models\MachinePriorityOrder;
use App\Models\MachinePriorityOrderAttribute;
use App\Models\MachinePriorityOrderAttributeValue;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductCustomer;
use App\Models\Spec;
use App\Models\Template;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class ProductImport implements ToCollection, WithHeadingRow, WithStartRow, WithCalculatedFormulas, WithMultipleSheets
{
    protected $fields;
    protected $product = null;

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
            $product = Product::find($row['id']);
            if (!$product) {
                $product = new Product();
                $product->id = $row['id'];
                $product->name = $row['name'];
                $product->his = $row['his'];
                $product->ver = $row['ver'];
                $product->save();
            }
            $customer = Customer::find($row['customer_id']);
            if (!$customer) {
                $customer = new Customer();
                $customer->id = $row['customer_id'];
                $customer->name = $row['customer_name'];
                $customer->save();
            }
            if ($product && $customer) {
                $productCustomer = ProductCustomer::where('product_id', $product->id)->where('customer_id', $customer->id)->first();
                if (!$productCustomer) {
                    $productCustomer = new ProductCustomer();
                    $productCustomer->product_id = $product->id;
                    $productCustomer->customer_id = $customer->id;
                    $productCustomer->save();
                }
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
