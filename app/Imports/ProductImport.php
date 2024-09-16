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
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

HeadingRowFormatter::default('none');
class ProductImport implements ToCollection, WithHeadingRow, WithStartRow, WithCalculatedFormulas
{
    protected $fields;
    protected $product = [];
    protected $material = [];
    protected $bom = [];
    protected $excel_headers = [];

    public function __construct($excel_headers = []) {
        $this->excel_headers = $excel_headers;
    }

    // Hàm này xác định hàng bắt đầu lấy tiêu đề (heading row)
    public function headingRow(): int
    {
        return 1;
    }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 3;
    }
    public function collection(Collection $collection)
    {
        Product::query()->delete();
        Material::query()->delete();
        Bom::query()->delete();
        Spec::query()->delete();
        MachinePriorityOrder::query()->delete();
        MachinePriorityOrderAttribute::query()->delete();
        MachinePriorityOrderAttributeValue::query()->delete();
        $this->fields = $collection->toArray();
        foreach ($collection as $index => $row) {
            if($index > 3){
                Log::debug($this->handleData($row->toArray()));
            }
        }
    }

    function handleData(array $row)
    {
        $product = [];
        $material = [];
        $bom = [];
        $customer = [];
        $specs = [];
        foreach ($row as $key_field => $value) {
            if (!$key_field || is_numeric($key_field) || !$value) {
                continue;
            }
            $keys = explode('.', $key_field);
            $table_name = $keys[0] ?? null; // Tên bảng
            $slug = $keys[1] ?? null; //Slug
            $line = $keys[2] ?? null; //Công đoạn (nếu có)
            // Log::debug([$table_name, $slug, $line, $value]);
            if ($table_name === 'products') {
                $product[$slug] = $value;
            }
            if ($table_name === 'material') {
                $material[$slug] = $value;
            }
            if ($table_name === 'bom') {
                $bom[$slug] = $value;
            }
            if ($table_name === 'customer') {
                $customer[$slug] = $value;
            }
            if ($table_name === 'spec' && isset($this->excel_headers[$key_field]) && !is_array($this->excel_headers[$key_field])) {
                $specs[] = [
                    'name' => $this->excel_headers[$key_field],
                    'slug' => $slug,
                    'product_id' => $this->product['id'],
                    'line_id' => $line,
                    'value'=>$value,
                ];
            }
        }
        if (!isset($product['id'])) {
            return;
        }
        if (isset($material['id']) && $product['id']) {
            $material['product_id'] = $product['id'];
            $material = $material;

            $bom['product_id'] = $product['id'];
            $bom['material_id'] = $material['id'];
            $bom = $bom;
        }
        return ['product' => $product, 'material' => $material, 'bom' => $bom, 'customer' => $customer];
    }
}
