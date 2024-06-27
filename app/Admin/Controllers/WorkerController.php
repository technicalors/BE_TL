<?php

namespace App\Admin\Controllers;

use App\Models\Line;
use App\Models\ProductionPlan;
use App\Models\Workers;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkerController extends AdminController
{



    public function upload_xlsx($action, $title)
    {
        return view('import', [
            "action" => $action,
            "title" => $title
        ]);
    }



    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Quản lý nhân viên';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Workers());
        $grid->actions(function ($actions) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();
        });

        $grid->tools(function ($tool) {
            $tool->append($this->upload_xlsx('/admin/workers/import', 'Chọn file import nhân viên thủ công'));
        });
        $grid->column('id', __('Mã nhân viên'));
        $grid->column('name', __('Họ và tên'));

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
        $show = new Show(Workers::findOrFail($id));

        $show->field('id', __('Mã nhân viên'));
        $show->field('name', __('Họ và tên'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new Workers());

        $form->text('name', __('Họ và tên'));

        return $form;
    }

    public function import($flag = false)
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
        Workers::truncate();
        $spreadsheet = $reader->load($_FILES['files']['tmp_name']);
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $index=1;
        foreach ($allDataInSheet as $row) {
            $index++;
            if ($index > 4)  {

                $plan = new  Workers();
                $plan->name = $row['B'];
                // dd($plan);
                try{
                    $plan->save();
                }catch( Exception $ex){

                }
                
            }
        }
        if ($flag) return true;
        admin_success('Tải lên thành công', 'success');
        return back();
    }
}
