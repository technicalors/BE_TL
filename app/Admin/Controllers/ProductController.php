<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\Line;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionJourney;
use App\Models\Spec;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Traits\API;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    use API;
    public function list(Request $request)
    {
        $query = Product::with('customer')->orderBy('created_at', 'DESC');
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->name)) {
            $query->where('name', 'like', "%$request->name%");
        }
        if (isset($request->customer_name)) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('customer_name', 'like', "%$request->customer_name%");
            });
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $query->offset(($request->page - 1) ?? 0)->limit($request->page * $request->pageSize);
        }
        $result = $query->get();
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        $validated = Product::validate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $product = Product::create($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $validated = Product::validate($input, $id);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        try {
            DB::beginTransaction();
            $product = Product::find($id)->update($input);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Cập nhật thành công');
    }

    public function delete(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $product = Product::find($id)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Xoá thành công');
    }

    public function deleteMultiple(Request $request)
    {
        try {
            DB::beginTransaction();
            $product = Product::whereIn('id', $request)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success('', 'Xoá thành công');
    }
    public function upload_xlsx($action, $title)
    {
        return view('import', [
            "action" => $action,
            "title" => $title
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        // try {
        //     Excel::import(new MoldsImport, $request->file('file'));
        // } catch (\Exception $e) {
        //     // Handle the exception and return an appropriate response
        //     return $this->failure(['error' => $e->getMessage()], 'File import failed', 422);
        // }
        return $this->success('', 'Upload thành công');
    }

    public function export(Request $request)
    {
        return $this->success('', 'Export thành công');
    }

    public function importNewVersion()
    {
        set_time_limit(0);
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
        $product_data = [];
        $material_data = [];
        $bom_data = [];
        $production_journeys_data = [];
        $material_wastages_data = [];
        $time_wastages_data = [];
        $line_productivities_data = [];
        $assigned_machine_personnels_data = [];
        $line_standards_data = [];
        $production_modes_data = [];
        foreach ($allDataInSheet as $index => $row) {
            if ($index < 5) {
                continue;
            }
            //Lọc dữ liệu
            $product_data[] = array_intersect_key($row, array_flip($this->product_columns));
            $material_data[] = array_intersect_key($row, array_flip($this->material_columns));
            $bom_data[] = array_intersect_key($row, array_flip($this->bom_columns));
            $production_journeys_data[] = array_intersect_key($row, array_flip($this->production_journey_column));
            $material_wastages_data[] = array_intersect_key($row, array_flip($this->material_wastage_columns));
            $time_wastages_data[] = array_intersect_key($row, array_flip($this->time_wastage_columns));
            $line_productivities_data[] = array_intersect_key($row, array_flip($this->line_productivity_columns));
            $assigned_machine_personnels_data[] = array_intersect_key($row, array_flip($this->assigned_machine_personnel_columns));
            $line_standards_data[] = array_intersect_key($row, array_flip($this->line_standard_columns));
            $production_modes_data[] = array_intersect_key($row, array_flip($this->production_mode_columns));
        }
        try {
            DB::beginTransaction();
            //Thực hiện import
            return $this->importProductionJourneys($production_journeys_data);
            admin_success('Tải lên thành công', 'success');
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            admin_error('Tải lên không thành công', 'failure');
        }

        return back();
    }
    protected $product_columns = [
        'B', 'C', 'D', 'E', 'F', 'G', 'AO', 'AP'
    ];
    //import product
    protected function importProduct($product_data)
    {
        $product = [];
        foreach ($product_data as $data) {
            $input = [];
            $input['id'] = $data['B'];
            $input['name'] = $data['C'];
            $input['ver'] = $data['D'];
            $input['his'] = $data['E'];
            $input['customer_id'] = $data['F'];
            $input['weight'] = $data['AO'];
            $input['paper_norm'] = $data['AP'];
            $product[] = $input;
        }
        Product::insert($product);
    }

    protected $material_columns = [
        'B', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q'
    ];
    //import material
    protected function importMaterial($material_data)
    {
        $material = [];
        foreach ($material_data as $data) {
            $input = [];
            $input['id'] = $data['I'];
            $input['name'] = $data['J'];
            $input['material'] = $data['L'];
            $input['color'] = $data['M'];
            $input['quantitative'] = $data['N'];
            $input['thickness'] = $data['O'];
            $input['meter_per_roll'] = $data['P'];
            $input['sheet_per_pallet'] = $data['Q'];
            $material[] = $input;
        }
        Material::insert($material);
    }

    protected $bom_columns = [
        'B', 'H', 'I', 'K'
    ];
    //import bom
    protected function importBom($bom_data)
    {
        $bom = [];
        foreach ($bom_data as $data) {
            $input = [];
            $input['product_id'] = $data['B'];
            $input['material_id'] = $data['I'];
            $input['ratio'] = $data['K'];
            $input['priority'] = $data['H'];
            $bom[] = $input;
        }
        Bom::insert($bom);
    }

    protected $production_journey_column = [
        'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN'
    ];
    //import production_journey
    protected function importProductionJourneys($production_journeys_data)
    {
        $production_journeys = [];
        foreach ($production_journeys_data as $data) {
            $input = [];
            $input['product_id'] = $data['B'];
            $input['line_id'] = $data['I'];
            $input['ordinal'] = $data['K'];
            $production_journeys[] = $input;
        }
        ProductionJourney::insert($production_journeys);
    }
    protected $material_wastage_columns = [
        'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ', 'BA', 'BB', 'BC', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BK'
    ];
    protected $time_wastage_columns = [
        'BL', 'BM', 'BN', 'BO', 'BP', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BV', 'BW', 'BX', 'BY', 'BZ', 'CA', 'CB', 'CC'
    ];
    protected $line_productivity_columns = [
        'CD', 'CE', 'CF', 'CG', 'CH', 'CI', 'CJ', 'CK', 'CL', 'CM', 'CN', 'CO'
    ];
    protected $assigned_machine_personnel_columns = [
        'CP', 'CQ', 'CR', 'CS', 'CT', 'CU', 'CV', 'CW', 'CX'
    ];
    protected $line_standard_columns = [
        'CY', 'CZ', 'DA', 'DB', 'DC', 'DD', 'DE', 'DF', 'DG', 'DH', 'DI', 'DJ', 'DK', 'DL', 'DM', 'DN', 'DO', 'DP', 'DQ', 'DR',
        'DS', 'DT', 'DU', 'DV', 'DW', 'DX', 'DY', 'DZ', 'EA', 'EB', 'EC', 'ED', 'EE', 'EF', 'EG', 'EH', 'EI', 'EJ', 'EK', 'EL',
        'EM', 'EN', 'EO', 'EP', 'EQ', 'ER', 'ES', 'ET', 'EU', 'EV', 'EW', 'EX', 'EY', 'EZ', 'FA', 'FB', 'FC', 'FD', 'FE', 'FF',
        'FG', 'FH', 'FI', 'FJ', 'FK', 'FL', 'FM', 'FN', 'FO', 'FP', 'FQ', 'FR', 'FS', 'FT', 'FU', 'FV', 'FW', 'FX', 'FY', 'FZ',
        'GA', 'GB', 'GC', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GJ', 'GK', 'GL', 'GM', 'GN', 'GO', 'GP', 'GQ', 'GR', 'GS', 'GT',
        'GU', 'GV', 'GW', 'GX', 'GY', 'GZ', 'HA', 'HB', 'HC', 'HD', 'HE', 'HF', 'HG', 'HH', 'HI', 'HJ', 'HK', 'HL', 'HM', 'HN',
        'HO', 'HP', 'HQ', 'HR', 'HS', 'HT', 'HU', 'HV', 'HW', 'HX', 'HY', 'HZ', 'IA', 'IB', 'IC', 'ID', 'IE', 'IF', 'IG', 'IH',
        'II', 'IJ', 'IK', 'IL', 'IM', 'IN', 'IO', 'IP', 'IQ', 'IR', 'IS', 'IT', 'IU', 'IV', 'IW', 'IX', 'IY', 'IZ', 'JA', 'JB',
        'JC', 'JD', 'JE', 'JF', 'JG', 'JH', 'JI', 'JJ', 'JK', 'JL', 'JM', 'JN', 'JO', 'JP', 'JQ', 'JR', 'JS', 'JT', 'JU', 'JV',
        'JW', 'JX', 'JY', 'JZ', 'KA', 'KB', 'KC'
    ];
    protected $production_mode_columns = [
        'KD', 'KE', 'KF', 'KG', 'KH', 'KI', 'KJ', 'KK', 'KL', 'KM', 'KN', 'KO', 'KP', 'KQ', 'KR', 'KS', 'KT', 'KU', 'KV', 'KW', 'KX', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LD', 'LE', 'LF', 'LG', 'LH', 'LI', 'LJ', 'LK', 'LL', 'LM', 'LN', 'LO', 'LP', 'LQ', 'LR', 'LS'
    ];

    /**
     * Make a grid builder.
     *
     * @return Grid
     */

    protected function grid()
    {
        $grid = new Grid(new Product());
        $grid->actions(function ($actions) {
            $actions->disableDelete();
            // $actions->disableEdit();
            $actions->disableView();
        });
        $grid->disableCreateButton();

        $grid->tools(function ($tool) {
            $tool->append($this->upload_xlsx('/admin/products/import', 'Chọn file sản phẩm/spec'));
        });
        $grid->column('id', __('Mã hàng'));
        $grid->column('name', __('Tên'));
        // $grid->column('unit_id', __('Unit id'));
        // $grid->column('info', __('Info'));
        $grid->column('material_id', __('Mã nguyên liệu'));
        $grid->column('customer.name', __('Khách hàng'));
        $grid->column('ver', __('Ver'));
        $grid->column('his', __('His'));
        $grid->column('nhiet_do_phong', __('Nhiệt độ phòng'));
        $grid->column('do_am_phong', __('Độ ẩm phòng'));
        $grid->column('do_am_giay', __('Độ ẩm giấy'));
        $grid->column('dinh_muc', __('số tờ/pallet'));
        $grid->column('thoi_gian_bao_on', __('Thời gian bảo ôn'));
        $grid->column('chieu_dai_thung', __('Chiều dài thùng(mm)'));
        $grid->column('chieu_rong_thung', __('Chiều rộng thùng(mm)'));
        $grid->column('chieu_cao_thung', __('Chiều cao thùng(mm)'));
        $grid->column('the_tich_thung', __('Thể tích thùng(m3)'));
        // $grid->column('created_at', __('Created at'));
        // $grid->column('updated_at', __('Updated at'));

        $grid->filter(function ($filter) {
            $filter->like('name', 'Name');
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
        $show = new Show(Product::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('name', __('Name'));
        $show->field('unit_id', __('Unit id'));
        $show->field('info', __('Info'));
        $show->field('material_id', __('Material id'));
        $show->field('customer_id', __('Customer id'));
        $show->field('nhiet_do_phong', __('Nhiet do phong'));
        $show->field('do_am_phong', __('Do am phong'));
        $show->field('do_am_giay', __('Do am giay'));
        $show->field('thoi_gian_cho', __('Thoi gian cho'));
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
        $form = new Form(new Product());

        // $form->text('name', __('Name'));
        // $form->text('unit_id', __('Unit id'));
        // $form->textarea('info', __('Info'));
        // $form->text('material_id', __('Material id'))->default('1');
        // $form->text('customer_id', __('Customer id'));
        // $form->text('nhiet_do_phong', __('Nhiet do phong'));
        // $form->text('do_am_phong', __('Do am phong'));
        // $form->text('do_am_giay', __('Do am giay'));
        // $form->text('thoi_gian_cho', __('Thoi gian cho'));
        $form->text('thoi_gian_bao_on', __('Thời gian bảo ôn'));
        $form->text('u_thoi_gian_u', __('Thời gian ủ'));

        return $form;
    }

    public function getSpecProduct(Request $request)
    {
        $query = Product::with('customer')->orderBy('created_at', 'DESC');
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->name)) {
            $query->where('name', 'like', "%$request->name%");
        }
        $products = $query->get();
        return $this->success($products);
    }
    public function updateSpecProduct(Request $request)
    {
        $input = $request->all();
        $customer = Customer::where('name', $input['customer'])->first();
        $input["customer_id"] = $customer ? $customer->id : null;
        $validated = Product::validateUpdate($input);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $product = Product::where('id', $input['id'])->first();
        if ($product) {
            $update = $product->update($input);
            return $this->success($product);
        } else {
            return $this->failure('', 'Không tìm thấy máy');
        }
    }

    public function createSpecProduct(Request $request)
    {
        $input = $request->all();
        $customer = Customer::where('name', $input['customer'])->first();
        $input["customer_id"] = $customer ? $customer->id : null;
        $validated = Product::validateUpdate($input, false);
        if ($validated->fails()) {
            return $this->failure('', $validated->errors()->first());
        }
        $product = Product::create($input);
        return $this->success($product, 'Tạo thành công');
    }

    public function deleteSpecProduct(Request $request)
    {
        $input = $request->all();
        foreach ($input as $key => $value) {
            Product::where('id', $value)->delete();
        }
        return $this->success('Xoá thành công');
    }

    public function exportSpecProduct(Request $request)
    {
        $query = Product::with('customer')->orderBy('created_at', 'DESC');
        if (isset($request->id)) {
            $query->where('id', 'like', "%$request->id%");
        }
        if (isset($request->name)) {
            $query->where('name', 'like', "%$request->name%");
        }
        $products = $query->get();
        foreach ($products as $product) {
            $product->customer_name = $product->customer->name;
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
        $header = ['Mã hàng', 'Tên', 'Mã nguyên liệu', 'Khách hàng', 'Ver', 'His', 'Nhiệt độ phòng', 'Độ ẩm phòng', 'Độ ẩm giấy', 'Số tờ/pallet', 'Thời gian bảo ôn', 'Chiều dài thùng', 'Chiều rộng thùng', 'Chiều cao thùng', 'Thể tích thùng', 'Định mức thùng', 'Nhiệt độ phòng ủ', 'Độ ẩm phòng ủ', 'Độ ẩm giấy ủ', 'Thời gian ủ', 'Number of bin', 'KT khổ dài', 'KT khổ rộng'];
        $table_key = [
            'A' => 'id',
            'B' => 'name',
            'C' => 'material_id',
            'D' => 'customer_name',
            'E' => 'ver',
            'F' => 'his',
            'G' => 'nhiet_do_phong',
            'H' => 'do_am_phong',
            'I' => 'do_am_giay',
            'J' => 'dinh_muc',
            'K' => 'thoi_gian_bao_on',
            'L' => 'chieu_dai_thung',
            'M' => 'chieu_rong_thung',
            'N' => 'chieu_cao_thung',
            'O' => 'the_tich_thung',
            'P' => 'dinh_muc_thung',
            'Q' => 'u_nhiet_do_phong',
            'R' => 'u_do_am_phong',
            'S' => 'u_do_am_giay',
            'T' => 'u_thoi_gian_u',
            'U' => 'number_of_bin',
            'V' => 'kt_kho_dai',
            'W' => 'kt_kho_rong',
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
        foreach ($products->toArray() as $key => $row) {
            $table_col = 1;
            $row = (array)$row;
            $sheet->setCellValue([1, $table_row], $key + 1)->getStyle([1, $table_row])->applyFromArray($centerStyle);
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
        header('Content-Disposition: attachment;filename="Sản phẩm.xlsx"');
        header('Cache-Control: max-age=0');
        header("Content-Transfer-Encoding: binary");
        header('Expires: 0');
        $writer =  new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('exported_files/Sản phẩm.xlsx');
        $href = '/exported_files/Sản phẩm.xlsx';
        return $this->success($href);
    }

    public function importSpecProduct(Request $request)
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
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 2
            if ($key > 2) {
                $input = [];
                $input['id'] = $row['A'];
                $input['name'] = $row['B'];
                $input['material_id'] = $row['C'];
                $customer = Customer::where('name', $row['D'])->first();
                $input["customer_id"] = $customer ? $customer->id : null;
                $input['ver'] = $row['E'];
                $input['his'] = $row['F'];
                $input['nhiet_do_phong'] = $row['G'];
                $input['do_am_phong'] = $row['H'];
                $input['do_am_giay'] = $row['I'];
                $input['dinh_muc'] = $row['J'];
                $input['thoi_gian_bao_on'] = $row['K'];
                $input['chieu_dai_thung'] = $row['L'];
                $input['chieu_rong_thung'] = $row['M'];
                $input['chieu_cao_thung'] = $row['N'];
                $input['the_tich_thung'] = $row['O'];
                $input['dinh_muc_thung'] = $row['P'];
                $input['u_nhiet_do_phong'] = $row['Q'];
                $input['u_do_am_phong'] = $row['R'];
                $input['u_do_am_giay'] = $row['S'];
                $input['u_thoi_gian_u'] = $row['T'];
                $input['number_of_bin'] = $row['U'];
                $input['kt_kho_dai'] = $row['V'];
                $input['kt_kho_rong'] = $row['W'];
                $validated = Product::validateUpdate($input);
                if ($validated->fails()) {
                    return $this->failure('', 'Lỗi dòng thứ ' . ($key) . ': ' . $validated->errors()->first());
                }
                $data[] = $input;
            }
        }
        foreach ($data as $key => $input) {
            $product = Product::where('id', $input['id'])->first();
            if ($product) {
                $product->update($input);
            } else {
                Product::create($input);
            }
        }
        return $this->success([], 'Upload thành công');
    }
}
