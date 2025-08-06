<?php

namespace App\Admin\Controllers;

use App\Models\TestCriteria;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Traits\API;
use App\Models\Line;
use Illuminate\Support\Facades\DB;

class TestCriteriaController extends AdminController
{
    use API;
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Chỉ tiêu kiểm tra';
    public function upload_xlsx($action, $title)
    {
        return view('import', [
            "action" => $action,
            "title" => $title
        ]);
    }
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new TestCriteria());
        $grid->actions(function ($actions) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();
        });
        $grid->tools(function ($tool) {
            $tool->append($this->upload_xlsx('/admin/test_criteria/import', 'Chọn file chỉ tiêu kiểm tra'));
        });
        // $grid->column('id', __('Id'));
        $grid->column('line.name', __('Công đoạn'));
        $grid->column('chi_tieu', __('Chỉ tiêu'));
        $grid->column('hang_muc', __('Hạng mục'));
        $grid->column('tieu_chuan', __('Tiêu chuẩn'));
        $grid->column('phan_dinh', __('Phán định'));
        // $grid->column('created_at', __('Created at'));
        // $grid->column('updated_at', __('Updated at'));

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
        $show = new Show(TestCriteria::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('line_id', __('Line id'));
        $show->field('chi_tieu', __('Chi tieu'));
        $show->field('hang_muc', __('Hang muc'));
        $show->field('tieu_chuan', __('Tieu chuan'));
        $show->field('phan_dinh', __('Phan dinh'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new TestCriteria());

        $form->number('line_id', __('Line id'));
        $form->text('chi_tieu', __('Chi tieu'));
        $form->text('hang_muc', __('Hang muc'));
        $form->text('tieu_chuan', __('Tieu chuan'));
        $form->text('phan_dinh', __('Phan dinh'))->default('OK/NG');

        return $form;
    }
    function readFilex($activeIndex = 0, $file_name)
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

        $spreadsheet = $reader->load($file_name);
        $spreadsheet->setActiveSheetIndex($activeIndex);
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        return $allDataInSheet;
    }

    public function getTestCriteria(Request $request)
    {
        $line_arr = [];
        $lines = Line::all();
        foreach ($lines as $line) {
            $line_arr[Str::slug($line->name)] = $line->id;
        }

        $query = TestCriteria::with('line')->orderBy('chi_tieu');
        if (isset($request->line)) {
            $line = Line::where('name', 'like', "%$request->line%")->pluck('id')->toArray();
            $query->whereHas('lines', function ($query) use ($line) {
                $query->whereIn('line_id', $line);
            });
        }
        if (isset($request->hang_muc)) {
            $query->where('hang_muc', 'like', "%$request->hang_muc%");
        }
        if (isset($request->chi_tieu)) {
            $query->where('chi_tieu', 'like', "%$request->chi_tieu%");
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $page = $request->page - 1;
            $pageSize = $request->pageSize;
            $query->offset($page * $pageSize)->limit($pageSize);
        }
        $test_criterias = $query->with('lines')->get()->sortBy('id', SORT_NATURAL)->values();
        foreach ($test_criterias as $key => $value) {
            $ref = Line::whereIn('id', explode(',', $value->reference ?? ''))->pluck('name')->toArray();
            $value->ref_line = ['name'=>implode(', ', $ref)];
        }
        $frequency = [TestCriteria::MOT_MAU_TREN_MOT_CUON, TestCriteria::MOT_MAU_TREN_MOT_CA];
        return $this->success(['data' => $test_criterias, 'total' => $total, 'frequency' => $frequency]);
    }
    public function updateTestCriteria(Request $request, $id)
    {
        $input = $request->all();
        if (!isset($input['line'])) {
            return $this->failure('', 'Không tìm thấy công đoạn');
        }
        if (isset($line_arr[Str::slug($input['reference'])])) {
            $input['reference'] = $line_arr[Str::slug($input['reference'])];
        }
        $validated = TestCriteria::validateUpdate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $test_criteria = TestCriteria::where('id', $id)->first();
        if ($test_criteria) {
            $update = $test_criteria->update($input);
            return $this->success($test_criteria);
        } else {
            return $this->failure('', 'Không tìm thấy chỉ tiêu');
        }
    }

    public function createTestCriteria(Request $request)
    {
        $line_arr = [];
        $lines = Line::all();
        foreach ($lines as $line) {
            $line_arr[Str::slug($line->name)] = $line->id;
        }

        $input = $request->all();
        if (isset($line_arr[Str::slug($input['line'])])) {
            $input['line_id'] = $line_arr[Str::slug($input['line'])];
        }
        if (isset($line_arr[Str::slug($input['reference'])])) {
            $input['reference'] = $line_arr[Str::slug($input['reference'])];
        }
        $validated = TestCriteria::validateUpdate($input, false);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $test_criteria = TestCriteria::create($input);
        return $this->success($test_criteria, 'Tạo thành công');
    }

    public function deleteTestCriteria(Request $request)
    {
        $input = $request->all();
        foreach ($input as $key => $value) {
            TestCriteria::where('id', $value)->delete();
        }
        return $this->success('Xoá thành công');
    }

    public function exportTestCriteria(Request $request)
    {
        $line_arr = [];
        $lines = Line::all();
        foreach ($lines as $line) {
            $line_arr[Str::slug($line->name)] = $line->id;
        }
        $query = TestCriteria::with('line')->orderBy('chi_tieu');
        if (isset($request->line)) {
            $query->where('line_id', $line_arr[Str::slug($request->line)]);
        }
        if (isset($request->hang_muc)) {
            $query->where('hang_muc', 'like', "%$request->hang_muc%");
        }
        $test_criterias = [];
        foreach ($query->get() as $key => $test_criteria) {
            if (str_replace(' ', '', $test_criteria->hang_muc) === "") {
                continue;
            }
            $test_criteria->line_name  = $test_criteria->line->name;
            $test_criteria->ref_line_name  = $test_criteria->ref_line ? $test_criteria->ref_line->name : '';
            $test_criterias[] = $test_criteria->toArray();
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
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => 'BFBFBF')
            ]
        ]);
        $titleStyle = array_merge($centerStyle, [
            'font' => ['size' => 16, 'bold' => true],
        ]);
        $border = [
            'borders' => array(
                'allBorders' => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => '000000'),
                ),
            ),
        ];
        $header = ['Công đoạn', 'Hạng mục', 'Chỉ tiêu', 'Tiêu chuẩn', 'Phân định', 'Tham chiếu TCKT công đoạn'];
        $table_key = [
            'A' => 'line_name',
            'B' => 'hang_muc',
            'C' => 'chi_tieu',
            'D' => 'tieu_chuan',
            'E' => 'phan_dinh',
            'F' => 'ref_line_name',
        ];
        foreach ($header as $key => $cell) {
            if (!is_array($cell)) {
                $sheet->setCellValue([$start_col, $start_row], $cell)->mergeCells([$start_col, $start_row, $start_col, $start_row])->getStyle([$start_col, $start_row, $start_col, $start_row])->applyFromArray($headerStyle);
            }
            $start_col += 1;
        }

        $sheet->setCellValue([1, 1], 'Quản lý thông số sản phẩm')->mergeCells([1, 1, $start_col - 1, 1])->getStyle([1, 1, $start_col - 1, 1])->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $table_col = 1;
        $table_row = $start_row + 1;
        foreach ($test_criterias as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            foreach ($table_key as $k => $value) {
                if (isset($row[$value])) {
                    $sheet->setCellValue($k . $table_row, $row[$value])->getStyle($k . $table_row)->applyFromArray($centerStyle);
                } else {
                    continue;
                }
                $table_col += 1;
            }
            $table_row += 1;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            $sheet->getStyle($column->getColumnIndex() . ($start_row) . ':' . $column->getColumnIndex() . ($table_row - 1))->applyFromArray($border);
        }
        header("Content-Description: File Transfer");
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Chỉ tiêu kiểm tra.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Chỉ tiêu kiểm tra.xlsx');
        $href = '/exported_files/Chỉ tiêu kiểm tra.xlsx';
        return $this->success($href);
    }

    public function importTestCriteria(Request $request)
    {
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
        $lines = Line::where('factory_id', 2)->get();
        foreach ($lines as $line) {
            $line_arr[Str::slug($line->name)] = $line->id;
        }
        $id_arr = [];
        $last_criteria = TestCriteria::orderByRaw('CHAR_LENGTH(id) DESC')->orderBy('id', 'DESC')->first();
        $index = ((int) filter_var($last_criteria->id ?? "", FILTER_SANITIZE_NUMBER_INT) ?? 0) + 1;
        $i = 0;
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 3
            if ($key > 2) {
                $input = [];
                if (in_array($row['A'], $id_arr)) {
                    admin_error('Lỗi dòng thứ ' . ($key) . ': Mã "' . $row['A'] . '" bị trùng');
                    return back();
                }
                if (!$row['A']) continue;
                $id = 'CT' . ($index + $i);
                $id_arr[] = $id;
                $input['id'] = $id;
                if (isset($line_arr[Str::slug($row['B'])])) {
                    $input['line_id'] = $line_arr[Str::slug($row['B'])];
                }
                $input['hang_muc'] = str_replace(array("\n", "\r\n", "\r"), ' ', $row['C']);
                $input['chi_tieu'] = $row['D'];
                $input['tieu_chuan'] = $row['F'];
                $input['phan_dinh'] = $row['H'];
                $input['reference'] = isset($line_arr[Str::slug($row['I'])]) ? $line_arr[Str::slug($row['I'])] : '';
                $validated = TestCriteria::validateUpdate($input);
                if ($validated->fails()) {
                    admin_error('Lỗi dòng thứ ' . ($key) . ': ' . $validated->errors()->first());
                    return back();
                }
                $input['is_show'] = 1;
                $data[] = $input;
                $i++;
            }
        }
        try {
            DB::beginTransaction();
            TestCriteria::query()->update(['is_show' => 0]);
            foreach ($data as $key => $input) {
                $test_criteria = TestCriteria::updateOrCreate(['id' => $input['id']], $input);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $e;
        }
        // return $this->success([], 'Upload thành công');
        admin_success('Tải lên thành công', 'success');
        return back();
    }

    public function import(Request $request)
    {
        $extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        if ($extension == 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        } elseif ($extension == 'xlsx') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        }
        // file path
        $spreadsheet = $reader->load($_FILES['file']['tmp_name']);
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $data = [];
        $line_arr = [];
        $lines = Line::where('factory_id', 2)->get();
        foreach ($lines as $line) {
            $line_arr[Str::slug($line->name)] = $line->id;
        }
        $id_arr = [];
        $lines = [];
        $so_chi_tieu = "";
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 3
            if ($key > 2) {
                $input = [];
                if (!$row['A']) continue;
                if (in_array($row['A'], $id_arr)) {
                    return $this->failure('', 'Lỗi dòng thứ ' . ($key) . ': Mã "' . $row['A'] . '" bị trùng');
                }
                $id_arr[] = $row['A'];
                $input['id'] = $row['A'];
                if (!empty($row['B'])) {
                    $value = explode('+', $row['B']);
                    $lines = Line::whereIn(DB::raw('LOWER(name)'), array_map('strtolower', array_map('trim', $value)))->pluck('id')->toArray();
                }
                if (count($lines) <= 0) {
                    continue;
                    return $this->failure('', 'Không tìm thấy công đoạn');
                }
                $input['line_ids'] = $lines;
                $input['hang_muc'] = str_replace(array("\n", "\r\n", "\r"), ' ', $row['C']);
                $input['chi_tieu'] = $row['D'];
                if(!empty($row['E'])){
                    $so_chi_tieu = trim($row['E']);
                }
                $input['so_chi_tieu'] = $so_chi_tieu;
                $input['tieu_chuan'] = $row['F'];
                $input['phan_dinh'] = $row['J'];
                if (!empty($row['I'])) {
                    $value = explode('+', $row['I']);
                    $reference = Line::whereIn(DB::raw('LOWER(name)'), array_map('strtolower', array_map('trim', $value)))->pluck('id')->toArray();
                    $input['reference'] = implode(',', $reference);
                }
                $input['frequency'] = trim($row['G']) == '1 mẫu đầu ca' ? TestCriteria::MOT_MAU_TREN_MOT_CA : TestCriteria::MOT_MAU_TREN_MOT_CUON;
                $validated = TestCriteria::validateUpdate($input);
                if ($validated->fails()) {
                    return $this->failure('', 'Lỗi dòng thứ ' . ($key) . ': ' . $validated->errors()->first());
                }
                $input['is_show'] = 1;
                $data[] = $input;
            }
        }
        try {
            DB::beginTransaction();
            TestCriteria::query()->delete();
            DB::table('test_criteria_line')->delete();
            foreach ($data as $key => $input) {
                $test_criteria = TestCriteria::create($input);
                $test_criteria->lines()->attach($input['line_ids'] ?? []);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
        return $this->success('', 'Tải lên thành công');
    }
}
