<?php

namespace App\Admin\Controllers;

use App\Models\Cell;
use App\Models\Sheft;
use App\Models\WareHouse;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;

class WarehouseController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'WareHouse';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        
        $grid = new Grid(new Cell());
        $grid->actions(function ($actions) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();
        });
        $grid->tools(function ($tool) {
            $tool->append($this->upload_xlsx('/admin/warehouse/import', 'Chọn file thông tin kho'));
        });
        $grid->column(__(' Mã kho'))->display(function () {
            $id = $this->sheft->warehouse->id;
            return "<span >$id</span>";
        });
        $grid->column(__('Kho'))->display(function () {
            $id = $this->sheft->warehouse->name;
            return "<span >$id</span>";
        });

        $grid->column("id", __('Rack'))->display(function () {
            return $this->id;
        });

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
        $show = new Show(WareHouse::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('name', __('Name'));
        $show->field('note', __('Note'));
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
        $form = new Form(new WareHouse());

        $form->text('name', __('Name'));
        $form->text('note', __('Note'));

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
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        // dd($allDataInSheet);
        WareHouse::truncate();
        Sheft::truncate();
        Cell::truncate();

        $mark_warehouse = [];
        $mark_sheft = [];
        $mark_cell = [];


        for ($i = 4; $i <= count($allDataInSheet); $i++) {
            $row = $allDataInSheet[$i];
            $ten_kho = $row['C'];
            $ma_kho = $row['D'];

            $ma_rack = $row['E'][0];
            $ma_cell = $row['E'];


            if (!isset($mark_warehouse[$ma_kho])) {
                $warehouse = new WareHouse();
                $warehouse->name = $ten_kho;
                $warehouse->save();
                $warehouse->id = $ma_kho;
                $warehouse->save();
                $mark_warehouse[$ma_kho] = $warehouse;
            } else {
                $warehouse = $mark_warehouse[$ma_kho];
            }

            if (!isset($mark_sheft[$ma_rack])) {
                $sheft = new Sheft();
                $sheft->name = $ma_rack;
                $sheft->warehouse_id = $warehouse->id;
                $sheft->save();
                $sheft->id = $ma_rack;
                $sheft->save();
                $mark_sheft[$ma_rack] = $sheft;
            }

            if (!isset($mark_cell[$ma_cell])) {
                $cell = new Cell();
                $cell->name = $ma_cell;
                $cell->sheft_id = $sheft->id;
                $cell->save();
                $cell->id = $ma_cell;
                $cell->save();
                $mark_sheft[$ma_cell] = $cell;
            }
        }
        admin_success('Tải lên thành công', 'success');
        return back();
    }
}
