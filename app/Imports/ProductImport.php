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
        return 3;
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
        if(!empty($row['ma_hang'])){
            $this->product = Product::updateOrCreate(['id' => $row['ma_hang']], ['name' => $row['ten_san_pham'], 'ver'=> $row['ver'], 'his' => $row['his']]);
        }
        if($this->product){
            ProductCustomer::updateOrCreate(['product_id' => $this->product->id, 'customer_id' => $row['ma_khach_hang']]);
            Customer::updateOrCreate(['id' => $row['ma_khach_hang']], ['name' => $row['ten_khach_hang']]);
        }
    }
}
