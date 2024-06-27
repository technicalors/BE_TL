<?php

namespace App\Admin\Controllers;

use App\Models\CheckSheet;
use App\Models\CheckSheetWork;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Hamcrest\Type\IsNumeric;

class CheckSheetController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'CheckSheet';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CheckSheet());
        $grid->tools(function ($tool) {
            $tool->append($this->upload_xlsx('/admin/check-sheets/import', 'Chọn file check-sheet'));
        });
        $grid->actions(function ($actions) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();
        });
        // $grid->column('id', __('Id'));
        $grid->column('line.name', __('Máy'))->label();
        $grid->column('hang_muc', __('Hạng mục'));
        $grid->column('checkSheetWork', __('Chi tiết công việc'))->display(
            function ($item) {
                $html = "";
                foreach ($item as $x) {
                    $congviec = $x['cong_viec'];
                    $html .= "<div>- {$congviec}</div> ";
                }
                return $html;
            }

        );
       


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
        $show = new Show(CheckSheet::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('hang_muc', __('Hang muc'));
        $show->field('active', __('Active'));
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
        $form = new Form(new CheckSheet());

        $form->text('hang_muc', __('Hang muc'));
        $form->number('active', __('Active'))->default(1);

        return $form;
    }

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



        $lines = [
            -1, 10, 12, 11, 13
        ];


        CheckSheet::truncate();
        CheckSheetWork::truncate();

        for ($k = 1; $k <= 4; $k++) {

            $datasheet =  $spreadsheet->getSheet($k)->toArray(null, true, true, true);
            $mark_hang_muc = [];
            for ($i = 1; $i < count($datasheet); $i++) {
                $row = $datasheet[$i];
                $hang_muc = "";
                $flag = false;
                if ((isset($row['B'][0]) && $row['B'][0] !== 'H' && $row['C'] !== 'ạ') && !$flag) {
                    $flag = true;
                }
                if (!$flag) continue;



                $hang_muc = $row['B'];
                $work_name = $row['C'];

                if (!isset($work_name) || !isset($hang_muc)) continue;

                $type =  is_numeric($row['F']);

                if (!isset($mark_hang_muc[$hang_muc])) {
                    $checksheet = new CheckSheet();
                    $checksheet->hang_muc = $hang_muc;
                    $checksheet->line_id = $lines[$k];
                    $checksheet->save();
                    $mark_hang_muc[$hang_muc] = $checksheet;
                } else {
                    $checksheet = $mark_hang_muc[$hang_muc];
                }
                $work  = new CheckSheetWork();
                $work->cong_viec = $work_name;
                $work->check_sheet_id = $checksheet->id;
                $work->type = $type;
                $work->save();
            }
        }

        admin_success('Tải lên thành công', 'success');
        return back();
    }
}
