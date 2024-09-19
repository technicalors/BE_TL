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

HeadingRowFormatter::default('none');
class ProductImport implements ToCollection, WithHeadingRow, WithStartRow, WithCalculatedFormulas, WithMultipleSheets
{
    protected $fields;
    protected $product = [];
    protected $material = [];
    protected $bom = [];
    protected $excel_headers = [];

    public function __construct($excel_headers = [])
    {
        Log::debug('ProductImport __construct called');
        $this->excel_headers = $excel_headers;
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
        return 1;
    }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 6;
    }
    public function collection(Collection $collection)
    {
        Log::debug('ProductImport collection called');
        $products = [];
        $this->fields = $collection->toArray();
        Product::query()->delete();
        Material::query()->delete();
        Bom::query()->delete();
        Spec::query()->delete();
        MachinePriorityOrder::query()->delete();
        MachinePriorityOrderAttribute::query()->delete();
        MachinePriorityOrderAttributeValue::query()->delete();
        foreach ($collection as $row) {
            [$product, $material, $bom, $customer, $specs, $machine_priority_orders] = $this->handleData($row->toArray());
            if (!empty($product['id'])) {
                $products[] = $product;
                Product::updateOrCreate(
                    ['id' => $product['id']],
                    $product,
                );
            }
            if (!empty($material['id'])) {
                Material::updateOrCreate(
                    ['id' => $material['id']],
                    $material,
                );
            }
            if (!empty($bom)) {
                Bom::create($bom);
            }
            if (!empty($customer['id'])) {
                Customer::updateOrCreate(
                    ['id' => $customer['id']],
                    $customer,
                );
            }
            if (!empty($specs)) {
                Spec::insert($specs);
            }
            if (!empty($machine_priority_orders)) {
                $machinePriorityOrder = null;
                foreach ($machine_priority_orders as $key => $value) {
                    if ($value['slug'] === 'machine_id') {
                        $previousMachinePriorityOrder = MachinePriorityOrder::where('product_id', $value['product_id'])->where('line_id', $value['line_id'])->orderBy('priority', 'DESC')->first();
                        $machinePriorityOrder = MachinePriorityOrder::create([
                            'product_id' => $value['product_id'],
                            'line_id' => $value['line_id'],
                            'machine_id' => $value['value'],
                            'priority' => (int)($previousMachinePriorityOrder->priority ?? 0) + 1,
                        ]);
                    } else {
                        if ($machinePriorityOrder && trim($value['value'])) {
                            $machinePriorityOrderAttribute = MachinePriorityOrderAttribute::firstOrCreate([
                                'name' => $title[$key] ?? "",
                                'slug' => $value['slug'],
                            ]);
                            if ($machinePriorityOrderAttribute) {
                                $machinePriorityOrderAttributeValues[] = [
                                    'machine_priority_order_attribute_id' => $machinePriorityOrderAttribute->id,
                                    'machine_priority_order_id' => $machinePriorityOrder->id,
                                    'value' => $value['value']
                                ];
                            }
                        }
                    }
                }
            }
        }
        if (count($products) < 0) {
            throw new Exception("Đã xảy ra lỗi, không có sản phẩm nào được tạo");
        }
    }

    function handleData(array $row)
    {
        $product = [];
        $material = [];
        $bom = [];
        $customer = [];
        $specs = [];
        $machine_priority_orders = [];
        foreach ($row as $key_field => $value) {
            if (!$key_field || is_numeric($key_field) || !$value) {
                continue;
            }
            $keys = explode('.', $key_field);
            $table_name = $keys[0] ?? null; // Tên bảng
            $slug = $keys[1] ?? null; //Slug
            $line = $keys[2] ?? null; //Công đoạn (nếu có)
            if ($table_name === 'products') {
                $product[$slug] = trim($value);
            }
            if ($table_name === 'material') {
                $material[$slug] = trim($value);
            }
            if ($table_name === 'bom') {
                $bom[$slug] = trim($value);
            }
            if ($table_name === 'customer') {
                $customer[$slug] = trim($value);
            }
            if ($table_name === 'spec' && !empty($value) && !empty($product['id']) && isset($this->excel_headers[$key_field]) && !empty($this->excel_headers[$key_field])) {
                $specs[] = [
                    'name' => $this->excel_headers[$key_field],
                    'slug' => $slug,
                    'product_id' => $product['id'],
                    'line_id' => $line,
                    'value' => trim($value),
                ];
            }
            if ($table_name === 'machine_priority_order' && !empty($value) && !empty($product['id']) && isset($this->excel_headers[$key_field]) && !empty($this->excel_headers[$key_field])) {
                $machine_priority_orders[] = [
                    'name' => $this->excel_headers[$key_field],
                    'slug' => $slug,
                    'product_id' => $product['id'],
                    'line_id' => $line,
                    'value' => trim($value),
                ];
            }
        }
        if (!empty($product['id'])) {
            $this->product = $product;
        }
        if (isset($material['id']) && !empty($this->product['id'])) {
            $bom['product_id'] = $this->product['id'];
            $bom['material_id'] = $material['id'];
        }
        return [$product, $material, $bom, $customer, $specs, $machine_priority_orders];
    }
}
