<?php

namespace App\Admin\Controllers;

use App\Models\TimeSet;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class TimesetController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Ca làm việc';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new TimeSet());

        // $grid->column('id', __('Id'));
        $grid->column('ten_ca', __('Tên ca'));
        $grid->column('thoi_gian_bat_dau', __('Thời gian bắt đầu'));
        $grid->column('thoi_gian_ket_thuc', __('Thời gian kết thúc'));
        $grid->column('nghi_giua_ca', __('Nghỉ giữa ca (phút)'));

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
        $show = new Show(TimeSet::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('ten_ca', __('Tên ca'));
        $show->field('thoi_gian_bat_dau', __('Thời gian bắt đầu'));
        $show->field('thoi_gian_ket_thuc', __('Thời gian kết thúc'));
        $show->field('nghi_giua_ca', __('Nghỉ giữa ca (phút)'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new TimeSet());

        $form->text('ten_ca', __('Tên ca'));
        $form->datetime('thoi_gian_bat_dau', __('Thời gian bắt đầu'))->default(date('Y-m-d H:i:s'));
        $form->datetime('thoi_gian_ket_thuc', __('Thời gian kết thúc'))->default(date('Y-m-d H:i:s'));
        $form->number('nghi_giua_ca', __('Nghỉ giữa ca'));

        return $form;
    }
}
