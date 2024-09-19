<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\StampsImport;
use App\Models\Material;
use App\Models\ExcelHeader;
use App\Models\Product;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;

class ExcelHeaderController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ExcelHeader::orderBy('id');
        $column_headers = $query->get();
        $columns = $this->buildTree($column_headers);
        $query = Product::with([
            'customer',
            'boms.material',
            'specs.line',
            'machinePriorityOrders.machine',
            'machinePriorityOrders.attributeValues.attribute',
        ]);
        $total = $query->count();
        if(isset($request->page) && isset($request->pageSize)){
            $query->offset(($request->page - 1) * $request->pageSize)->limit($request->pageSize);
        }
        $products = $query->get();
        foreach ($products as $product) {
            foreach ($product->boms as $bom) {
                $item = [];
    
                // Product fields
                $item['products.id'] = $product->id;
                $item['products.name'] = $product->name;
                $item['products.ver'] = $product->ver;
                $item['products.his'] = $product->his;
    
                // Customer fields
                if ($product->customer) {
                    $item['customer.id'] = $product->customer->id;
                    $item['customer.name'] = $product->customer->name;
                }
    
                // BOM fields
                $item['bom.priority'] = $bom->priority;
                $item['bom.ratio'] = $bom->ratio;
    
                // Material fields
                if ($bom->material) {
                    $item['material.id'] = $bom->material->id;
                    $item['material.name'] = $bom->material->name;
                    $item['material.material'] = $bom->material->material;
                    $item['material.color'] = $bom->material->color;
                    $item['material.quantitative'] = $bom->material->quantitative;
                    $item['material.thickness'] = $bom->material->thickness;
                    $item['material.meter_per_roll'] = $bom->material->meter_per_roll;
                    $item['material.sheet_per_pallet'] = $bom->material->sheet_per_pallet;
                }
    
                // Specs
                foreach ($product->specs as $spec) {
                    if ($spec->line) {
                        $item["spec.{$spec->slug}.{$spec->line->id}"] = $spec->value;
                    } else {
                        $item["spec.{$spec->slug}"] = $spec->value;
                    }
                }
    
                // Machine Priority Orders
                foreach ($product->machinePriorityOrders as $mpo) {
                    $line = $mpo->line;
                    if ($mpo->machine) {
                        $item["machine_priority_order.machine_id.{$line->id}"] = $mpo->machine->name;
                    }
                    foreach ($mpo->attributeValues as $attributeValue) {
                        $slug = $attributeValue->attribute->slug;
                        $value = $attributeValue->value;
            
                        $item["machine_priority_order.{$slug}.{$line->id}"] = $value;
                    }
                }
    
                $results[] = $item;
            }
        }
        return $this->success(['data'=>$results, 'total'=>$total, 'columns' => $columns]);
    }

    function buildTree($headers, $parentId = null) {
        $branch = [];
    
        foreach ($headers as $header) {
            if ($header->parent_id == $parentId) {
                // Tính toán width dựa trên độ dài của title
                $titleLength = strlen($header->header_name) * 15;
                $children = $this->buildTree($headers, $header->id);
                // Nếu có con, tính width dựa trên tổng độ dài của tất cả các con
                $nodeWidth = $titleLength;
                if (!empty($children)) {
                    $nodeWidth = array_reduce($children, function ($carry, $child) {
                        return $carry + $child['width'];
                    }, 0);
                }
                // Tạo node
                $node = [
                    'title' => $header->header_name,
                    'dataIndex' => $header->field_name,
                    'width' => $nodeWidth,
                    'align' => 'center',
                    'ellipsis' => true,
                ];
                // Thêm con nếu có
                if (!empty($children)) {
                    $node['children'] = $children;
                }
                $branch[] = $node;
            }
        }
    
        return $branch;
    }

    public function store(Request $request)
    {
        $excel_headers = ExcelHeader::create($request->all());
        return $this->success($excel_headers);
    }

    public function show($id)
    {
        $excel_headers = ExcelHeader::find($id);
        if (!$excel_headers) {
            return $this->success('', 'ExcelHeader not found');
        }
        return $this->success($excel_headers);
    }

    public function update(Request $request, $id)
    {
        $excel_headers = ExcelHeader::find($id);
        if (!$excel_headers) {
            return $this->failure('', 'ExcelHeader not found');
        }
        $excel_headers->update($request->all());

        return $this->success($excel_headers);
    }

    public function destroy($id)
    {
        $excel_headers = ExcelHeader::find($id);

        if (!$excel_headers) {
            return $this->failure('', 'ExcelHeader not found');
        }

        $excel_headers->delete();

        return $this->success('', 'ExcelHeader deleted');
    }

    function insertHeader($sheet, $allData, $parent, $start, $range, $mergedCells, $start_row)
    {
        if(count($allData) < $start){
            return 'break';
        }
        foreach ($range as $key) {
            if ($start === 0) {
                $parent = null;
            }
            $mergeCell = $this->checkHorizontalMergedCell($mergedCells, $key . $start_row);
            if ($mergeCell) {
                $parent_header = ExcelHeader::firstOrCreate([
                    'header_name' => $allData[$start][$key] ?? "",
                    'column_position' => $mergeCell,
                    'section' => null,
                    'parent_id' => $parent->id ?? null,
                    'field_name' => Str::slug($allData[$start][$key] ?? ""),
                ]);
                $next_row_index = $start + 1;
                $next_start_row = $start_row + 1;
                $first_key = preg_replace('/[^a-zA-Z]/', '', explode(':', $mergeCell)[0]);
                $last_key = preg_replace('/[^a-zA-Z]/', '', explode(':', $mergeCell)[1]);
                $first_index = filter_var(explode(':', $mergeCell)[0], FILTER_SANITIZE_NUMBER_INT);
                $last_index = filter_var(explode(':', $mergeCell)[1], FILTER_SANITIZE_NUMBER_INT);
                if($last_index > $first_index){
                    $next_row_index += $last_index - $first_index;
                    $next_start_row += $last_index - $first_index;
                }
                $this->insertHeader($sheet, $allData, $parent_header, $next_row_index, $this->excelColumnRange($first_key, $last_key), $mergedCells, $next_start_row);
            } else {
                if (!empty($allData[$start][$key])) {
                    $position = $key.$start_row;
                    $mergeCell = $this->checkVerticalMergedCell($mergedCells, $key.$start_row);
                    if($mergeCell){
                        $position = $mergeCell;
                    }
                    $excel_header = ExcelHeader::firstOrCreate([
                        'header_name' => $allData[$start][$key] ?? "",
                        'column_position' => $position,
                        'section' => null,
                        'parent_id' => $parent->id ?? null,
                        'field_name' => Str::slug($allData[$start][$key] ?? ""),
                    ]);
                    if (!empty($allData[$start + 1][$key])) {
                        $position = $key.($start_row + 1);
                        $child = ExcelHeader::firstOrCreate([
                            'header_name' => $allData[$start + 1][$key] ?? "",
                            'column_position' => $position,
                            'section' => null,
                            'parent_id' => $excel_header->id ?? null,
                            'field_name' => Str::slug($allData[$start + 1][$key] ?? ""),
                        ]);
                    }
                }
            }
        }
        return 'done';
    }

    function checkHorizontalMergedCell($mergedCells, $cell)
    {
        foreach ($mergedCells as $cells) {
            // Kiểm tra nếu ô nằm trong vùng hợp nhất
            if ($cell === explode(':', $cells)[0]) {
                // Lấy chỉ số hàng bắt đầu và kết thúc của vùng hợp nhất
                $startRow = filter_var(explode(':', $cells)[0], FILTER_SANITIZE_NUMBER_INT);
                $endRow = filter_var(explode(':', $cells)[1], FILTER_SANITIZE_NUMBER_INT);
                $startCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[0]);
                $endCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[1]);
                // Nếu hàng bắt đầu và kết thúc giống nhau thì ô này là merge cell trên cùng 1 hàng
                if ($startRow === $endRow || $startCol !== $endCol) {
                    return $cells;
                }
            }
        }
        return false;
    }

    function checkVerticalMergedCell($mergedCells, $cell)
    {
        foreach ($mergedCells as $cells) {
            // Kiểm tra nếu ô nằm trong vùng hợp nhất
            if ($cell === explode(':', $cells)[0]) {
                // Lấy chỉ số hàng bắt đầu và kết thúc của vùng hợp nhất
                $startCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[0]);
                $endCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[1]);
                // Nếu hàng bắt đầu và kết thúc giống nhau thì ô này là merge cell trên cùng 1 hàng
                if ($startCol === $endCol) {
                    return $cells;
                }
            }
        }
        return false;
    }

    function excelColumnRange($start_col, $end_col = null, ...$additional_cols)
    {
        // Nếu không có $end_col, đặt $end_col là $start_col
        if ($end_col === null) {
            $end_col = $start_col;
        }

        $start_num = $this->columnToNumber($start_col);
        $end_num = $this->columnToNumber($end_col);

        $columns = [];
        for ($i = $start_num; $i <= $end_num; $i++) {
            $columns[] = $this->numberToColumn($i);
        }

        // Thêm các cột bất kỳ vào mảng kết quả
        foreach ($additional_cols as $col) {
            if (!in_array($col, $columns)) {
                $columns[] = $col;
            }
        }

        return $columns;
    }

    // Chuyển đổi tên cột Excel thành số thứ tự
    function columnToNumber($col)
    {
        $num = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $num;
    }

    // Chuyển đổi số thứ tự thành tên cột Excel
    function numberToColumn($num)
    {
        $col = '';
        while ($num > 0) {
            $remainder = ($num - 1) % 26;
            $col = chr(65 + $remainder) . $col;
            $num = intval(($num - 1) / 26);
        }
        return $col;
    }

    public function import(Request $request)
    {
        if (!isset($_FILES['file'])) { {
                return $this->failure('', 'Định dạng file không đúng');
            }
        }
        $extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        if ($extension == 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        } elseif ($extension == 'xlsx') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        }
        $spreadsheet = $reader->load($_FILES['file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        ExcelHeader::truncate();
        $mergedCells = $sheet->getMergeCells();
        $parent = null;
        $start = 0;
        $first_key = array_key_first($allDataInSheet[2]);
        $last_key = array_key_last($allDataInSheet[2]);
        $this->insertHeader($sheet, array_splice($allDataInSheet, 1, 3), $parent, $start, $this->excelColumnRange($first_key, $last_key), $mergedCells, 2);
        return $this->success('', 'Import thành công');
    }

    public function export()
    {
        //Tiêu đề
        $query = ExcelHeader::orderByRaw('column_position');
        $headers = $query->get();
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $last_row_index = 1;
        //Chèn tiêu đề
        function setValue($sheet, $headers, $row, &$last_row_index)
        {
            $styleArray = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => 'FF000000'],
                    ],
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ];
            foreach ($headers as $key => $header) {
                if (str_contains($header->column_position, ':')) {
                    $sheet->mergeCells($header->column_position)->setCellValue(explode(':', $header->column_position)[0], $header->header_name)->getStyle($header->column_position)->applyFromArray($styleArray);
                } else {
                    $sheet->setCellValue($header->column_position, $header->header_name)->getStyle($header->column_position)->applyFromArray($styleArray);
                }
            }
        }
        setValue($sheet, $headers, 1, $last_row_index);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/TestSpec.xlsx');
        $href = '/exported_files/TestSpec.xlsx';
        return $this->success($href);
    }

    // Hàm tính cột tiếp theo trong Excel
    function nextExcelColumn(string $currentColumn, int $steps = 1): string
    {
        // Nếu $steps = 0 thì trả về cột hiện tại mà không thay đổi
        if ($steps === 0) {
            return $currentColumn;
        }

        // Chuyển đổi chữ cái thành số dựa trên thứ tự A=1, B=2, ..., Z=26
        $columnLength = strlen($currentColumn);
        $columnNumber = 0;

        // Tính giá trị số nguyên của tên cột hiện tại
        for ($i = 0; $i < $columnLength; $i++) {
            $columnNumber = $columnNumber * 26 + (ord($currentColumn[$i]) - ord('A') + 1);
        }

        // Tính giá trị số nguyên của cột tiếp theo
        $nextColumnNumber = $columnNumber + $steps;

        // Chuyển đổi ngược lại từ số thành tên cột Excel
        $nextColumn = '';
        while ($nextColumnNumber > 0) {
            $mod = ($nextColumnNumber - 1) % 26;
            $nextColumn = chr($mod + ord('A')) . $nextColumn;
            $nextColumnNumber = (int)(($nextColumnNumber - 1) / 26);
        }

        return $nextColumn;
    }
}
