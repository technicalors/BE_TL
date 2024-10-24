<?php

namespace App\Admin\Controllers;

use App\Models\InfoCongDoan;
use Encore\Admin\Controllers\AdminController;
use App\Models\CustomUser;
use App\Models\Line;
use Illuminate\Http\Request;
use App\Traits\API;

class InfoCongDoanController extends AdminController
{
    use API;
    public function __construct(CustomUser $customUser)
    {
        $this->user = $customUser;
    }

    public function getInfoCongDoan(Request $request){
        $line_ids = Line::where('factory_id', 2)->pluck('id')->toArray();
        $query = InfoCongDoan::with('line')->whereIn('line_id', $line_ids)->where('status', InfoCongDoan::STATUS_COMPLETED)->orderBy('lot_id')->orderBy('thoi_gian_bat_dau');
        if(isset($request->date) && count($request->date) > 1){
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
            ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        if(isset($request->line_id)){
            if(is_array($request->line_id)){
                $query->whereIn('line_id', $request->line_id);
            }else{
                $query->where('line_id', $request->line_id);
            }
        }
        if(isset($request->lot_id)){
            $query->where('lot_id', 'like', "%$request->lot_id%");
        }
        $info_cong_doan = $query->get();
        return $this->success($info_cong_doan);
    }
    public function updateInfoCongDoan(Request $request){
        $input = $request->all();
        $validated = InfoCongDoan::validateUpdate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $info_cong_doan = InfoCongDoan::where('lot_id', $input['lot_id'])->where('line_id', $input['line_id'])->first();
        // return array_intersect_key($input, array_flip($info_cong_doan->getFillable()));
        $info_cong_doan->update($input);
        return $this->success($info_cong_doan);
    }
    public function exportInfoCongDoan(Request $request){
        $query = InfoCongDoan::with('line')
        ->whereNotIn('line_id', [9, 21])->whereHas('lot', function ($lot_query) {
            $lot_query->where(function ($q) {
                $q->where('info_cong_doan.line_id', 13)->whereIn('type', [0, 2, 3]);
            })->orWhere(function ($q) {
                $q->where('info_cong_doan.line_id', '<>', 13)->whereIn('type', [0, 1, 2, 3]);
            });
        })->orderBy('lot_id')->orderBy('created_at');
        if(isset($request->date) && count($request->date) > 1){
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($request->date[0])))
            ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        if(is_array($request->line_id)){
            $query->whereIn('line_id', $request->line_id);
        }else{
            $query->where('line_id', $request->line_id);
        }
        if(isset($request->product_id)){
            $query->where('lot_id', 'like', "%$request->product_id%");
        }
        if(isset($request->lo_sx)){
            $query->where('lot_id', 'like', "%$request->lo_sx%");
        }
        $info_cong_doan = $query->get();
        foreach($info_cong_doan as $info){
            $info->line_name = $info->line->name;
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
            'borders' => array(
                'outline' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $headerStyle = array_merge($centerStyle, [
            'font' => ['bold' => true],
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size'=>16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['STT', 'Mã pallet/thùng', 'Công đoạn', 'Thời gian bắt đầu', 'Thời gian bấm máy', 'Thời gian kết thúc', 'Sản lượng đầu vào vào hàng', 'Sản lượng đầu ra vào hàng', 'Sản lượng đầu vào thực tế', 'Sản lượng đầu ra thực tế', 'Số lượng tem vàng', 'Số lượng NG'];
        $table_key = [
            'A'=>'stt', 
            'B'=>'lot_id', 
            'C'=>'line_name',
            'D'=>'thoi_gian_bat_dau', 
            'E'=>'thoi_gian_bam_may',
            'F'=>'thoi_gian_ket_thuc', 
            'G'=>'sl_dau_vao_chay_thu', 
            'H'=>'sl_dau_ra_chay_thu',
            'I'=>'sl_dau_vao_hang_loat',
            'J'=>'sl_dau_ra_hang_loat',
            'K'=>'sl_tem_vang',
            'L'=>'sl_ng',
        ];
        foreach($header as $key => $cell){
            if(!is_array($cell)){
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col+=1;
        }
        $sheet->setCellValue([1, 1], 'Quản lý sản lượng')->mergeCells([1, 1, $start_col-1, 1])->getStyle([1, 1, $start_col-1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row+1;
        foreach($info_cong_doan->toArray() as $key => $row){
            $table_col = 1;
            $row = (array)$row;
            $sheet->setCellValue([1, $table_row],$key+1)->getStyle([1, $table_row])->applyFromArray($centerStyle);
            foreach($table_key as $k=>$value){
                if(isset($row[$value])){
                    $sheet->setCellValue($k.$table_row,$row[$value])->getStyle($k.$table_row)->applyFromArray($centerStyle);
                }else{
                    continue;
                }
                $table_col+=1;
            }
            $table_row+=1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex().($start_row).':'.$column->getColumnIndex().($table_row-1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Sản_lượng.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Sản_lượng.xlsx');
        $href = '/exported_files/Sản_lượng.xlsx';
        return $this->success($href);
    }
    public function importInfoCongDoan(Request $request){
        $extension = pathinfo($_FILES['files']['name'], PATHINFO_EXTENSION);
        if ($extension == 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        } elseif ($extension == 'xlsx') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        }
        // file path
        $spreadsheet = $reader->load($_FILES['files']['tmp_name']);
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $data = [];
        $line_arr = [];
        $lines = Line::all();
        foreach($lines as $line){
            $line_arr[Str::slug($line->name)] = $line->id;
        }
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 2
            if ($key > 2) {
                $input = [];
                $input['lot_id'] = $row['B'];
                $input['line_id'] = isset($line_arr[Str::slug($row['C'])]) ? $line_arr[Str::slug($row['C'])] : '';
                $input['thoi_gian_bat_dau'] = $row['D'];
                $input['thoi_gian_bam_may'] = $row['E'];
                $input['thoi_gian_ket_thuc'] = $row['F'];
                $input['sl_dau_vao_chay_thu'] = $row['G'];
                $input['sl_dau_ra_chay_thu'] = $row['H'];
                $input['sl_dau_vao_hang_loat'] = $row['I'];
                $input['sl_dau_ra_hang_loat'] = $row['J'];
                $input['sl_tem_vang'] = $row['K'];
                $input['sl_ng'] = $row['L'];
                $validated = InfoCongDoan::validateUpdate($input);
                if ($validated->fails()) {
                    return $this->failure('', 'Lỗi dòng thứ '.($key).': '.$validated->errors()->first());
                }
                $data[] = $input;
            }
        }
        foreach ($data as $key => $input) {
            $info_cong_doan = InfoCongDoan::where('lot_id', $input['lot_id'])->where('line_id', $input['line_id'])->first();
            $info_cong_doan->update($input);
        }
        return $this->success([], 'Upload thành công');
    }

    public function searchInfoCongDoan(Request $request){
        $query = InfoCongDoan::with(['line', 'product.customer', 'plan'])->orderBy('lot_id')->orderBy('thoi_gian_bat_dau');
        
        if(isset($request->line_id)){
            if(is_array($request->line_id)){
                $query->whereIn('line_id', $request->line_id);
            }else{
                $query->where('line_id', $request->line_id);
            }
        }

        if(isset($request->lot_id)){
            $query->where('lot_id', 'like', "%$request->lot_id%");
        }

        if(isset($request->start_date)) $query->whereDate('created_at', '>=', $request->start_date);
        else $query->whereDate('created_at', '>=', date('Y-m-d'));

        if(isset($request->end_date)) $query->whereDate('created_at', '<=', $request->end_date);
        else $query->whereDate('created_at', '<=', date('Y-m-d'));

        $info_cong_doan = $query->get();
        return $this->success($info_cong_doan);
    }
}
