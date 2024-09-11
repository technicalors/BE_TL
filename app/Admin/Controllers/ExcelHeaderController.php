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

class ExcelHeaderController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = ExcelHeader::orderBy('column_position');
        $result = $query->get();
        return $this->success($result);
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

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv',
        ]);

        try {
            Excel::import(new StampsImport, $request->file('file'));

            return $this->success('', 'Import successful');
        } catch (\Exception $e) {
            return $this->failure($e, 'Import failed');
        }
    }

    public function export()
    {
        //Tiêu đề
        $query = ExcelHeader::orderBy('column_position');
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
        //Gộp ô
        // function mergeCellDown($sheet, $headers, $current_row_index, $last_row_index)
        // {
        //     foreach ($headers as $key => $header) {
        //         if (count($header->children) <= 0) {
        //             $sheet->mergeCells($header->column_position . $current_row_index . ':' . $header->column_position . '4');
        //         } else {
        //             $current_row_index += 1;
        //             mergeCellDown($sheet, $header->children, $current_row_index, $last_row_index);
        //         }
        //     }
        // }
        // mergeCellDown($sheet, $headers, 1, $last_row_index);

        // //Nội dung
        // $query = ExcelHeader::whereNotNull('field_name')->orderBy('column_position');
        // $fields = $query->get()->groupBy('section')->map(function ($section) {
        //     return $section->pluck('field_name', 'column_position');
        // });
        // // return $fields;
        // $data = DB::table('products as p')
        //     ->leftJoin('bom as b', 'p.id', '=', 'b.product_id')
        //     ->leftJoin('material as m', 'b.material_id', '=', 'm.id')
        //     ->select(
        //         'p.id as product_id',
        //         'p.name as product_name',  // Đổi tên cột product_name
        //         'p.ver',
        //         'p.his',
        //         'p.customer_id',
        //         'b.priority',
        //         'b.ratio',
        //         'm.id as material_id',
        //         'm.name as material_name', // Đổi tên cột material_name
        //         'm.material',
        //         'm.color',
        //         'm.quantitative',
        //         'm.thickness',
        //         'm.meter_per_roll',
        //         'm.sheet_per_pallet'
        //     )
        //     ->orderBy('p.id')
        //     ->orderBy('b.priority')
        //     ->get();
        // // return $data;
        // $last_row_index += 1;
        // foreach ($data as $rowIndex => $record) {
        //     foreach (ExcelHeader::TABLE_LIST as $value) {
        //         foreach ($fields[$value] as $column => $field) {
        //             $sheet->setCellValue($column . ($last_row_index + $rowIndex), $record->$field ?? "");
        //         }
        //     }
        // }
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
