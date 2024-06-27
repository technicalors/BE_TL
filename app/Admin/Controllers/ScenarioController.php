<?php

namespace App\Admin\Controllers;

use App\Models\Category;
use App\Models\Scenario;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class ScenarioController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Scenario';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Scenario());

        // $grid->column('id', __('Id'));
        $grid->column('category.name', __('Danh mục'));
        $grid->column('hang_muc', __('Hạng mục'));
        $grid->column('tieu_chuan', __('Tiêu chuẩn'));
        $grid->column('tieu_chuan_max', __('Tiêu chuẩn max'));
        $grid->column('tieu_chuan_min', __('Tiêu chuẩn min'));
        $grid->column('tieu_chuan_kiem_soat_tren', __('Tiêu chuẩn kiểm soát trên'));
        $grid->column('tieu_chuan_kiem_soat_duoi', __('Tiêu chuẩn kiểm soát dưới'));
        $grid->column('color', __('màu sắc'))->display(function ($color){
            return "<div style='height:25px;width:25px;background-color:$color'></div>";
        });
        $grid->column('phuong_phap', __('Phương pháp'));
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
        $show = new Show(Scenario::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('category_id', __('Category id'));
        $show->field('phan_loai', __('Phan loai'));
        $show->field('tieu_chuan', __('Tieu chuan'));
        $show->field('tieu_chuan_max', __('Tieu chuan max'));
        $show->field('tieu_chuan_min', __('Tieu chuan min'));
        $show->field('tieu_chuan_kiem_soat_tren', __('Tieu chuan kiem soat tren'));
        $show->field('tieu_chuan_kiem_soat_duoi', __('Tieu chuan kiem soat duoi'));
        $show->field('color', __('Color'));
        $show->field('phuong_phap', __('Phuong phap'));
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
        $form = new Form(new Scenario());

        $categories = Category::all()->pluck("name","id")->toArray();
       
        $form->select('category_id', __('Danh mục'))->options($categories);
        $form->text('hang_muc', __('Hạng muc'));
        $form->decimal('tieu_chuan', __('Tiêu chuẩn'));
        $form->decimal('tieu_chuan_max', __('Tiêu chuẩn max'));
        $form->decimal('tieu_chuan_min', __('Tiêu chuẩn min'));
        $form->decimal('tieu_chuan_kiem_soat_tren', __('Tiêu chuẩn kiểm soát trên'));
        $form->decimal('tieu_chuan_kiem_soat_duoi', __('Tiêu chuẩn kiểm soát dưới'));
        $form->color('color', __('Màu sắc'));
        $form->text('phuong_phap', __('Phương pháp'));

        return $form;
    }
}
