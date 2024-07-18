<?php

namespace App\Admin\Controllers;

use App\Models\Machine;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use App\Traits\API;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Models\Line;

class MachineController extends AdminController
{
    use API;
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Máy móc,thiết bị';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Machine());
        $grid->actions(function ($actions) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();
        });

        $grid->column('name', __('Tên'));
        $grid->column('kieu_loai', __('Kiểu,loại'));
        $grid->column('ma_so', __('Mã số'));
        $grid->column('cong_suat', __('Công suất'));
        $grid->column('hang_sx', __('Hãng sản xuất'));
        $grid->column('nam_sd', __('Năm sử dụng'));
        $grid->column('don_vi_sd', __('Đơn vị sử dụng'));
        $grid->column('tinh_trang', __('Tình trạng'));
        $grid->column('vi_tri', __('Vị trí'));

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(Machine::findOrFail($id));
        

        $show->field('id', __('Id'));
        $show->field('name', __('Name'));
        $show->field('kieu_loai', __('Kieu loai'));
        $show->field('ma_so', __('Ma so'));
        $show->field('line_id', __('Line id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('cong_suat', __('Cong suat'));
        $show->field('hang_sx', __('Hang sx'));
        $show->field('nam_sd', __('Nam sd'));
        $show->field('don_vi_sd', __('Don vi sd'));
        $show->field('tinh_trang', __('Tinh trang'));
        $show->field('vi_tri', __('Vi tri'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new Machine());

        $form->text('name', __('Name'));
        $form->text('kieu_loai', __('Kieu loai'));
        $form->text('ma_so', __('Ma so'));
        $form->text('line_id', __('Line id'));
        $form->text('cong_suat', __('Cong suat'));
        $form->text('hang_sx', __('Hang sx'));
        $form->number('nam_sd', __('Nam sd'));
        $form->text('don_vi_sd', __('Don vi sd'));
        $form->text('tinh_trang', __('Tinh trang'));
        $form->text('vi_tri', __('Vi tri'));

        return $form;
    }

    public function getMachine(Request $request){
        $query = Machine::with('line')->orderBy('created_at');
        if(isset($request->code)){
            $query->where('code', 'like', "%$request->code%");
        }
        if(isset($request->name)){
            $query->where('name', 'like', "%$request->name%");
        }
        $machines = $query->get();
        return $this->success($machines);
    }
    public function updateMachine(Request $request){
        $line_arr = [];
        $lines = Line::all();
        foreach($lines as $line){
            $line_arr[Str::slug($line->name)] = $line->id;
        }

        $input = $request->all();
        if(isset($line_arr[Str::slug($input['line'])])){
            $input['line_id'] = $line_arr[Str::slug($input['line'])];
        }
        $validated = Machine::validateUpdate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $machine = Machine::where('id', $input['id'])->first();
        if($machine){
            $update = $machine->update($input);
            return $this->success($machine);
        }
        else{
            return $this->failure('', 'Không tìm thấy máy');
        }
    }

    public function createMachine(Request $request){
        $line_arr = [];
        $lines = Line::all();
        foreach($lines as $line){
            $line_arr[Str::slug($line->name)] = $line->id;
        }

        $input = $request->all();
        if(isset($line_arr[Str::slug($input['line'])])){
            $input['line_id'] = $line_arr[Str::slug($input['line'])];
        }
        $validated = Machine::validateUpdate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $machine = Machine::create($input);
        return $this->success($machine, 'Tạo thành công');
    }

    public function deleteMachine(Request $request){
        $input = $request->all();
        foreach ($input as $key => $value) {
            Machine::where('id', $value)->delete();
        }
        return $this->success('Xoá thành công');
    }

    public function exportMachine(Request $request){
        $query = Machine::with('line')->orderBy('created_at');
        if(isset($request->code)){
            $query->where('code', 'like', "%$request->code%");
        }
        if(isset($request->name)){
            $query->where('name', 'like', "%$request->name%");
        }
        $machines = $query->get();
        foreach($machines as $machine){
            $machine->line_name = $machine->line->name;
            $machine->is_iot = $machine->is_iot ? 'Có' : 'Không';
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $start_row = 2;
        $start_col = 1;
        $centerStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
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
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
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
        $header = ['STT', 'Tên', 'Kiểu loại', 'Code', 'Mã số', 'Công đoạn', 'Công suất', 'Hãng sản suất', 'Năm sử dụng', 'Đơn vị sử dụng', 'Tình trạng', 'Vị trí', 'IOT'];
        $table_key = [
            'A'=>'stt', 
            'B'=>'name', 
            'C'=>'kieu_loai',
            'D'=>'code',
            'E'=>'ma_so', 
            'F'=>'line_name',
            'G'=>'cong_suat', 
            'H'=>'hang_sx', 
            'I'=>'nam_sd',
            'J'=>'don_vi_sd',
            'K'=>'tinh_trang',
            'L'=>'vi_tri',
            'M'=>'is_iot'
        ];
        foreach($header as $key => $cell){
            if(!is_array($cell)){
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col+=1;
        }
        $sheet->setCellValue([1, 1], 'Quản lý máy')->mergeCells([1, 1, $start_col-1, 1])->getStyle([1, 1, $start_col-1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row+1;
        foreach($machines->toArray() as $key => $row){
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
        header('Content-Disposition: attachment;filename="Máy.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Máy.xlsx');
        $href = '/exported_files/Máy.xlsx';
        return $this->success($href);
    }

    public function importMachine(Request $request){
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
            if ($key > 4) {
                $input = [];
                $input['name'] = $row['B'];
                $input['kieu_loai'] = $row['C'];
                $input['code'] = $row['D'];
                $input['ma_so'] = $row['E'];
                $input['line_id'] = isset($line_arr[Str::slug($row['F'])]) ? $line_arr[Str::slug($row['F'])] : '';
                $input['cong_suat'] = $row['G'];
                $input['hang_sx'] = $row['H'];
                $input['nam_sd'] = $row['I'];
                $input['don_vi_sd'] = $row['J'];
                $input['tinh_trang'] = $row['K'];
                $input['vi_tri'] = $row['L'];
                $input['is_iot'] = $row['M'] === "Có" ? 1 : 0;
                $validated = Machine::validateUpdate($input);
                if ($validated->fails()) {
                    return $this->failure('', 'Lỗi dòng thứ '.($key).': '.$validated->errors()->first());
                }
                $data[] = $input;
            }
        }
        foreach ($data as $key => $input) {
            $machine = Machine::where('code', $input['code'])->first();
            if ($machine) {
                $machine->update($input);
            } else {
                Machine::create($input);
            }
        }
        return $this->success([], 'Upload thành công');
    }
}
