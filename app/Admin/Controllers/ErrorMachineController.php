<?php

namespace App\Admin\Controllers;

use App\Models\ErrorMachine;
use App\Models\Line;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Str;

class ErrorMachineController extends AdminController
{

    public function upload_xlsx($action, $title)
    {
        return view('import', [
            "action" => $action,
            "title" => $title
        ]);
    }

    public function import()
    {

        if (!isset($_FILES['files'])) { {
                admin_error('Định dạng file không đúng', 'error');
                return back();
            }
        }

        ErrorMachine::truncate();


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
        $allDataInSheet = $spreadsheet->getSheet(2)->toArray(null, true, true, true);

        for ($i = 7; $i <= count($allDataInSheet); $i++) {

            $row = $allDataInSheet[$i];
            // if (!isset($row['B']) || !isset($row["C"])) continue;
            $label1 = ["B", "C", "D", "E"];
            $label2 = ["F", "G", "H", "I"];
            $label3 = ["J", "K", "L", "M"];
            $label4 = ["N", "O", "P", "Q"];
            $this->createError($label1, $row, 10);
            $this->createError($label2, $row, 11);
            $this->createError($label3, $row, 12);
            $this->createError($label4, $row, 13);
        }
        admin_success('Tải lên thành công', 'success');
        return back();
    }


    private function createError($label, $row, $line_id)
    {
        if ($row[$label[0]] == "") return;
        $id = Str::slug($row[$label[0]]);
        if ($id == "ma-loi") return;
        $error = new ErrorMachine();
        $error->id = $id;
        $error->noi_dung = $row[$label[1]];
        $error->nguyen_nhan = $row[$label[2]];
        $error->khac_phuc = $row[$label[3]];
        $error->phong_ngua = "";
        $error->line_id = $line_id;
        $error->save();
        return $error;
    }


    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Lỗi dừng máy';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new ErrorMachine());

        $grid->column('id', __('ID'))->sortable();
        $grid->column('code', __('Mã lỗi'))->sortable();
        $grid->column('noi_dung', __('Nội dung'));
        $grid->column('nguyen_nhan', __('Nguyên nhân'));
        $grid->column('khac_phuc', __('Khắc phục'));

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
        $show = new Show(ErrorMachine::findOrFail($id));


        $show->field('id', __('Id'));
        $show->field('code', __('Mã lỗi'));
        $show->field('noi_dung', __('Nội dung'));
        $show->field('nguyen_nhan', __('Nguyên nhân'));
        $show->field('khac_phuc', __('Khắc phục'));
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
        $arr = [];
        $lines = Line::whereIn('id',['10','11','12','13','14','22'])->get();
        $arr[] = 'Tất cả';
        foreach ($lines as $key => $line) {
            $arr[$line->id] = $line->name;
        }
        $form = new Form(new ErrorMachine());
        $form->text('code', __('Mã lỗi'));
        $form->text('noi_dung', __('Nội dung'));
        $form->text('nguyen_nhan', __('Nguyên nhân'));
        $form->text('khac_phuc', __('Khắc phục'));
        $form->select('line_id', __('Công đoạn'))->options($arr);
        return $form;
    }
}
