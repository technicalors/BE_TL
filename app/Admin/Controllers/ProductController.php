<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Imports\ProductImport;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\ExcelHeader;
use App\Models\Line;
use App\Models\LineProductivity;
use App\Models\Machine;
use App\Models\MachinePriorityOrder;
use App\Models\MachinePriorityOrderAttribute;
use App\Models\MachinePriorityOrderAttributeValue;
use App\Models\MachineProductionMode;
use App\Models\Material;
use App\Models\MaterialWastage;
use App\Models\Product;
use App\Models\ProductCustomer;
use App\Models\ProductionJourney;
use App\Models\Spec;
use App\Models\TimeWastage;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Traits\API;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        if (isset($request->withs)) {
            $query->with($request->withs);
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $result = $query->get();
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function show($id)
    {
        $result = Product::find($id);
        return $this->success($result);
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
        return $this->success($product, 'Tạo thành công');
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
            $product = Product::find($id);
            if ($product) {
                $product->update($input);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success($product, 'Cập nhật thành công');
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

    public function import()
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');
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

        $materials = Material::all();
        $customers = Customer::all();

        $products = Product::all();

        Spec::truncate();

        foreach ($products as $item) {
            $item->delete();
        }
        // $products->delete();
        $mark_material = [];
        $mark_customer = [];
        $mark_product = [];

        foreach ($materials as $item) {
            // dd($item->id);
            $mark_material[$item->id] = true;
        }
        foreach ($customers as $item) {
            $mark_customer[$item->id] = true;
        }

        // dd($mark_material);

        $titleRow1 = $allDataInSheet[3];
        $titleRow2 = $allDataInSheet[4];
        for ($i = 5; $i <= count($allDataInSheet); $i++) {
            $row = $allDataInSheet[$i];

            if (isset($mark_product[$row['B']]) || $row['B'] == '' || $row['H'] == '') continue;
            $product = new Product();
            $product->id = str_replace(" ", "", $row["B"]);
            $product->name = $row["C"];
            $product->customer_id = $row["F"];
            $product->material_id = $row["H"];
            $product->dinh_muc = $row["N"];
            $product->dinh_muc_thung = $row["DC"];
            $product->nhiet_do_phong = $row["EF"];
            $product->do_am_phong = $row["EG"];
            $product->do_am_giay = $row["EH"];
            $product->thoi_gian_bao_on = $row["EI"];
            $product->chieu_dai_thung = $row["DE"];
            $product->chieu_rong_thung = $row["DF"];
            $product->chieu_cao_thung = $row["DG"];
            $product->the_tich_thung = $row["DH"];
            $product->kt_kho_dai = $row["BC"];
            $product->kt_kho_rong = $row["BE"];
            $product->ver = $row['D'];
            $product->his = $row['E'];
            $product->u_nhiet_do_phong = $row['EO'];
            $product->u_do_am_phong = $row['EP'];
            $product->u_do_am_giay = $row['EQ'];
            $product->u_thoi_gian_u = $row['ER'];
            $this->importSpec($row, $titleRow1, $titleRow2, $product->id);
            $product->save();
            $mark_product[$row['B']] = true;

            if (!isset($mark_material[$row['H']])) {
                $material = new Material();
                $material->id = $row['H'];
                $material->ten = $row['I'];
                $material->code = $row['J'];
                $info = [
                    "mau" => $row['K'],
                    "DL" => $row['L']
                ];

                $material->thong_so = $info;
                $material->save();
                $mark_material[$row['H']] = true;
            }
            if ($row['F'] == '') continue;
            if (!isset($mark_customer[$row['F']])) {
                $customer = new Customer();
                $customer->name = $row['G'];
                $customer->id = $row['F'];
                $customer->save();
                $mark_customer[$row['F']] = true;
            }
        }

        admin_success('Tải lên thành công', 'success');
        return back();
    }

    private $specId = 1;

    private function importSpec($currRow, $titleRow1, $titleRow2, $product)
    {
        $title = [];
        foreach ($titleRow2 as $i => $item) {
            $title[$i] = $titleRow2[$i];
            if (!isset($item)) {
                $title[$i] = $titleRow1[$i];
            }
        }

        $spec_data = [];
        foreach ($currRow as $key => $item) {
            $line_id = [];
            if (in_array($key, $this->excelColumnRange("DW", "EU", "AD", "AR", "BC", "BL", "BU", "CF", "CP", "DB"))) {
                $line_id = [24]; //Gap dan lien hoan
            } 
            else if (in_array($key, $this->excelColumnRange("GI", "HF", "AE", "AS", "BD", "BM", "BV", "CG", "CQ", "DC"))) {
                $line_id = [27]; //Dan liner
            } 
            else if (in_array($key, $this->excelColumnRange("EV", "GH", "AF", "AU", "BE", "BN", "BW", "CH", "CS", "DE"))) {
                $line_id = [25]; //In flexo
            } 
            else if (in_array($key, $this->excelColumnRange("IF", "JY", "AI", "AY", "BH", "BQ", "BZ", "CK", "CW", "DG"))) {
                $line_id = [26]; //Duc cat
            } 
            else if (in_array($key, $this->excelColumnRange("", "", "AN", "CD", "DA"))) {
                $line_id = [29]; //Chon Phase2 
            } 
            // else if (in_array($key, $this->excelColumnRange("LJ", "MA"))) {
            //     $line_id = [30]; //OQC Phase2
            // } 
            else if (in_array($key, $this->excelColumnRange("JZ", "KD", "CE", "CO"))) {
                $line_id = [24, 27, 25, 26, 29, 30];
            }

            foreach ($line_id as $id) {
                $input = [];
                if (!empty($item)) {
                    $input['name'] = "";
                    if ($key === 'CE') {
                        $input['name'] = 'Thời gian lên xuống cuộn';
                    } else if ($key === 'CO') {
                        $input['name'] = 'Số lượng cuộn 1 lần vận chuyển (Cuộn)';
                    } else if (in_array($key, $this->excelColumnRange("R", "AN"))) {
                        $input['name'] = 'Hành trình sản xuất';
                    } else if (in_array($key, $this->excelColumnRange("AR", "BB"))) {
                        $input['name'] = "Hao phí vào hàng các công đoạn";
                    } else if (in_array($key, $this->excelColumnRange("BC", "BK"))) {
                        $input["name"] = "Hao phí sản xuất các công đoạn (%)";
                    } else if (in_array($key, $this->excelColumnRange("BL", "BT"))) {
                        $input["name"] = "Chuẩn bị(Đầu ca)";
                    } else if (in_array($key, $this->excelColumnRange("BU", "CD"))) {
                        $input["name"] = "Vận chuyển (chuyển hàng công đoạn trước sang công đoạn sau)";
                    } else if (in_array($key, $this->excelColumnRange("CF", "CN"))) {
                        $input["name"] = "Vào hàng (Setup máy)";
                    } else if (in_array($key, $this->excelColumnRange("CP", "DA"))) {
                        $input["name"] = "Năng suất ấn định/giờ";
                    } else if (in_array($key, $this->excelColumnRange("DB", "DJ"))) {
                        $input["name"] = "Nhân sự ấn định máy (người)";
                    } else {
                        $input['name'] = $title[$key];
                    }
                    $input['id'] = $this->specId;
                    $input['value'] = $item;
                    $input['product_id'] = $product->id;
                    $input['slug'] = Str::slug($input['name']);
                    $input['line_id'] = $id;
                    $spec_data[] = $input;
                    if ($input['slug'] === 'so-bat' && $input['value']) {
                        $product->update(['so_bat' => $input['value']]);
                    }
                    $this->specId += 1;
                }
            }
        }
        Spec::insert($spec_data);
    }

    public function export(Request $request)
    {
        return $this->success('', 'Export thành công');
    }

    public function importNewVersion(Request $request)
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');
        if (!isset($_FILES['file'])) { {
                return $this->failure('', 'Định dạng file không đúng');
            }
        }
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
        $sheet = $spreadsheet->getActiveSheet();
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $product_data = [];
        $material_data = [];
        $product = null;
        $material = null;
        $titleRow1 = $allDataInSheet[3];
        $titleRow2 = $allDataInSheet[4];
        try {
            DB::beginTransaction();
            Spec::query()->delete();
            foreach ($allDataInSheet as $index => $row) {
                if ($index < 5) {
                    continue;
                }
                //Lọc dữ liệu
                $product_data[] = array_intersect_key($row, array_flip($this->product_columns));
                if (trim($row['B'])) {
                    $product = $this->importProduct(array_intersect_key($row, array_flip($this->product_columns)));
                    // $production_journey = ProductionJourney::create(['product_id' => $product->id], array_intersect_key($row, array_flip($this->production_journey_column)));
                    $this->importSpec($row, $titleRow1, $titleRow2, $product);
                }
                if ($product) {
                    $this->importMachinePriorityOrder($row, $titleRow2, $product->id, $index);
                }
                $material_data[] = array_intersect_key($row, array_flip($this->material_columns));
                if (trim($row['I'])) {
                    $material = $this->importMaterial(array_intersect_key($row, array_flip($this->material_columns)));
                    if ($material && $product) {
                        Bom::firstOrCreate(['product_id' => $product->id, 'material_id' => $row['I']], ['ratio' => $row['K'], 'priority' => $row['H']]);
                    }
                }
                if (trim($row['F'])) {
                    Customer::firstOrCreate(['id' => $row['F']], ['name' => $row['G']]);
                }
            }
            DB::commit();
        } catch (\Exception $th) {
            DB::rollBack();
            Log::debug($th);
            return $this->failure('', $th->getMessage());
        }

        return $this->success('', 'Import thành công');
    }
    protected $product_columns = [
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'AO',
        'AP'
    ];
    //import product
    protected function importProduct($product_data)
    {
        $input = [];
        $input['id'] = trim($product_data['B']);
        $input['name'] = $product_data['C'];
        $input['ver'] = $product_data['D'];
        $input['his'] = $product_data['E'];
        $input['customer_id'] = $product_data['F'];
        $input['weight'] = $product_data['AO'];
        $input['paper_norm'] = $product_data['AP'];
        $product[] = $input;
        $product = Product::firstOrCreate(['id' => $input['id']], $input);
        return $product;
    }

    protected $material_columns = [
        'B',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q'
    ];
    //import material
    protected function importMaterial($material_data)
    {
        $input['id'] = $material_data['I'];
        $input['code'] = $material_data['L'];
        $input['name'] = $material_data['J'];
        $input['material'] = $material_data['L'];
        $input['color'] = $material_data['M'];
        $input['quantitative'] = $material_data['N'];
        $input['thickness'] = $material_data['O'];
        $input['meter_per_roll'] = $material_data['P'];
        $input['sheet_per_pallet'] = $material_data['Q'];
        if (!empty($input['id'])) {
            $material = Material::firstOrCreate(['id'=>$input['id']], $input);
            return $material;
        }
        return null;
    }

    protected $bom_columns = [
        'B',
        'H',
        'I',
        'K'
    ];
    //import bom
    protected function importBom($bom_data)
    {
        $bom = [];
        foreach ($bom_data as $data) {
            if (trim($data['B']) && trim($data['I'])) {
                $input = [];
                $input['product_id'] = trim($data['B']);
                $input['material_id'] = trim($data['I']);
                $input['ratio'] = $data['K'];
                $input['priority'] = $data['H'];
                $bom[] = $input;
            }
        }
        Bom::insert($bom);
    }

    protected $production_journey_column = [
        'B',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z',
        'AA',
        'AB',
        'AC',
        'AD',
        'AE',
        'AF',
        'AG',
        'AH',
        'AI',
        'AJ',
        'AK',
        'AL',
        'AM',
        'AN'
    ];
    public function handleProductionJourney($production_journeys_data)
    {
        $filteredLine = [];
        $index = 0;
        foreach ($production_journeys_data as $data) {
            if ($data['B']) {
                if (count(array_filter($data, 'is_numeric')) > 0) {
                    $filteredLine[$index] = array_filter($data, 'is_numeric');
                    $filteredLine[$index]['B'] = $data['B'];
                    $index++;
                }
            }
        }
        return $filteredLine;
    }
    protected $lines = [
        'U' => 'Chia',
        'AD' => 24,
        'AE' => 27,
        'AF' => 25,
        'AI' => 26,
        'AN' => 29,
    ];
    //import production_journey
    protected function importProductionJourneys($filteredLine)
    {
        $production_journeys = [];
        foreach ($filteredLine as $line_in_order) {
            foreach ($line_in_order as $key => $value) {
                $input = [];
                if (isset($this->lines[$key])) {
                    $input['product_id'] = trim($line_in_order['B']);
                    $input['line_id'] = $this->lines[$key];
                    $input['ordinal'] = $value;
                    $production_journeys[] = $input;
                }
            }
        }
        ProductionJourney::insert($production_journeys);
    }
    protected $material_wastage_columns = [
        'AQ',
        'AR',
        'AS',
        'AT',
        'AU',
        'AV',
        'AW',
        'AX',
        'AY',
        'AZ',
        'BA',
        'BB',
        'BC',
        'BD',
        'BE',
        'BF',
        'BG',
        'BH',
        'BI',
        'BJ',
        'BK'
    ];
    function importMaterialWastages($data, $product_id)
    {
        $create_input = [];
        $line_id = null;
        $type = 1;
        foreach ($data as $key => $value) {
            if ($key == 'AQ') {
                $type = 1;
            }
            if ($key == 'BC') {
                $type = 2;
            }
            switch ($key) {
                case 'AR':
                case 'BC':
                    $line_id = 24; //Gap dan lien hoan
                    break;
                case 'AU':
                case 'BE':
                    $line_id = 25; //In offset
                    break;
                case 'AY':
                case 'BH':
                    $line_id = 26; //Be - Duc cat
                    break;
                case 'AS':
                case 'BD':
                    $line_id = 27; //Liner
                    break;
                default:
                    # code...
                    break;
            }
            $input = [];
            if (!$line_id || !$product_id || !$value) {
                continue;
            }
            $input['product_id'] = $product_id;
            $input['line_id'] = $line_id;
            $input['type'] = $type;
            $input['value'] = $value;
            $create_input[] = $input;
        }
        MaterialWastage::insert($create_input);
    }
    protected $time_wastage_columns = [
        'BL',
        'BM',
        'BN',
        'BO',
        'BP',
        'BQ',
        'BR',
        'BS',
        'BT',
        'BU',
        'BV',
        'BW',
        'BX',
        'BY',
        'BZ',
        'CA',
        'CB',
        'CC',
        'CD',
        'CE',
        'CF',
        'CG',
        'CH',
        'CI',
        'CJ',
        'CK',
        'CL',
        'CM'
    ];
    function importTimeWastages($data, $product_id)
    {
        $create_input = [];
        $line_id = null;
        $type = 1;
        foreach ($data as $key => $value) {
            if ($key == 'BL') {
                $type = 1;
            }
            if ($key == 'BU') {
                $type = 2;
            }
            if ($key == 'CE') {
                $type = 3;
            }
            switch ($key) {
                case 'BL':
                case 'BU':
                case 'CE':
                    $line_id = 24; //Gap dan lien hoan
                    break;
                case 'BN':
                case 'BW':
                case 'CG':
                    $line_id = 25; //In offset
                    break;
                case 'BQ':
                case 'BZ':
                case 'CJ':
                    $line_id = 26; //Be - Duc cat
                    break;
                case 'BM':
                case 'BV':
                case 'CF':
                    $line_id = 27; //Liner
                    break;
                default:
                    # code...
                    break;
            }
            $input = [];
            if (!$line_id || !$product_id || !$value) {
                continue;
            }
            $input['product_id'] = $product_id;
            $input['line_id'] = $line_id;
            $input['type'] = $type;
            $input['value'] = $value;
            $create_input[] = $input;
        }
        TimeWastage::insert($create_input);
    }
    protected $line_productivity_columns = [
        'CN',
        'CO',
        'CP',
        'CQ',
        'CR',
        'CS',
        'CT',
        'CU',
        'CV',
        'CW',
        'CX',
        'CY'
    ];
    protected $assigned_machine_personnel_columns = [
        'CX',
        'CY',
        'CZ',
        'DA',
        'DB',
        'DC',
        'DD',
        'DE',
        'DF',
        'DG',
        'DH'
    ];
    //from DI to KJ
    protected $line_standard_columns = [
        'DI',
        'DJ',
        'DK',
        'DL',
        'DM',
        'DN',
        'DO',
        'DP',
        'DQ',
        'DR',
        'DS',
        'DT',
        'DU',
        'DV',
        'DW',
        'DX',
        'DY',
        'DZ',
        'EA',
        'EB',
        'EC',
        'ED',
        'EE',
        'EF',
        'EG',
        'EH',
        'EI',
        'EJ',
        'EK',
        'EL',
        'EM',
        'EN',
        'EO',
        'EP',
        'EQ',
        'ER',
        'ES',
        'ET',
        'EU',
        'EV',
        'EW',
        'EX',
        'EY',
        'EZ',
        'FA',
        'FB',
        'FC',
        'FD',
        'FE',
        'FF',
        'FG',
        'FH',
        'FI',
        'FJ',
        'FK',
        'FL',
        'FM',
        'FN',
        'FO',
        'FP',
        'FQ',
        'FR',
        'FS',
        'FT',
        'FU',
        'FV',
        'FW',
        'FX',
        'FY',
        'FZ',
        'GA',
        'GB',
        'GC',
        'GD',
        'GE',
        'GF',
        'GG',
        'GH',
        'GI',
        'GJ',
        'GK',
        'GL',
        'GM',
        'GN',
        'GO',
        'GP',
        'GQ',
        'GR',
        'GS',
        'GT',
        'GU',
        'GV',
        'GW',
        'GX',
        'GY',
        'GZ',
        'HA',
        'HB',
        'HC',
        'HD',
        'HE',
        'HF',
        'HG',
        'HH',
        'HI',
        'HJ',
        'HK',
        'HL',
        'HM',
        'HN',
        'HO',
        'HP',
        'HQ',
        'HR',
        'HS',
        'HT',
        'HU',
        'HV',
        'HW',
        'HX',
        'HY',
        'HZ',
        'IA',
        'IB',
        'IC',
        'ID',
        'IE',
        'IF',
        'IG',
        'IH',
        'II',
        'IJ',
        'IK',
        'IL',
        'IM',
        'IN',
        'IO',
        'IP',
        'IQ',
        'IR',
        'IS',
        'IT',
        'IU',
        'IV',
        'IW',
        'IX',
        'IY',
        'IZ',
        'JA',
        'JB',
        'JC',
        'JD',
        'JE',
        'JF',
        'JG',
        'JH',
        'JI',
        'JJ',
        'JK',
        'JL',
        'JM',
        'JN',
        'JO',
        'JP',
        'JQ',
        'JR',
        'JS',
        'JT',
        'JU',
        'JV',
        'JW',
        'JX',
        'JY',
        'JZ',
        'KA',
        'KB',
        'KC',
        'KD',
        'KE',
        'KF',
        'KG',
        'KH',
        'KI',
        'KJ'
    ];
    //from KK to MR
    protected $production_mode_columns = [
        'KK',
        'KL',
        'KM',
        'KN',
        'KO',
        'KP',
        'KQ',
        'KR',
        'KS',
        'KT',
        'KU',
        'KV',
        'KW',
        'KX',
        'KY',
        'KZ',
        'LA',
        'LB',
        'LC',
        'LD',
        'LE',
        'LF',
        'LG',
        'LH',
        'LI',
        'LJ',
        'LK',
        'LL',
        'LM',
        'LN',
        'LO',
        'LP',
        'LQ',
        'LR',
        'LS',
        'LT',
        'LU',
        'LV',
        'LW',
        'LX',
        'LY',
        'LZ',
        'MA',
        'MB',
        'MC',
        'MD',
        'ME',
        'MF',
        'MG',
        'MH',
        'MI',
        'MJ',
        'MK',
        'ML',
        'MM',
        'MN',
        'MO',
        'MP',
        'MQ',
        'MR'
    ];

    public function importMachinePriorityOrder($row, $title, $productId, $rowIndex)
    {
        $columnGroups = [
            [
              'line_id'    => 24,
              'machineCol' => 'KI',
              'paramCols'  => ['KI','KJ','KK','KL','KM','KN'],  // hoặc $this->excelColumnRange('KI','KN')
              'uph' => 'CP',
            ],
            [
              'line_id'    => 25,
              'machineCol' => 'KO',
              'paramCols'  => ['KO','KP','KQ','KR','KS','KT','KU','KV','KW','KX','KY','KZ','LA','LB','LC','LD','LE','LF','LG','LH','LI','LJ','LK'],
              'uph' => 'CS',
            ],
            [
                'line_id'    => 27,
                'machineCol' => 'LP',
                'paramCols'  => ['LP','LQ','LR','LS','LT','LU','LV','LW','LX','LY','LZ','MA','MB','MC','MD','ME'],
                'uph' => 'CQ',
            ],
            [
                'line_id'    => 26,
                'machineCol' => 'LZ',
                'paramCols'  => ['LZ','MA','MB','MC','MD','ME','MF','MG','MH','MI','MJ','MK','ML','MM','MN','MO','MP','MQ','MR'],
                'uph' => 'CW',
            ],
            [
                'line_id'    => 29,
                'machineCol' => '',
                'paramCols'  => [],
                'uph' => 'DA',
            ],
        ];
        $uph = 0;
        foreach ($columnGroups as $group) {
            $machineCode = trim($row[$group['machineCol']] ?? '');
            if (!$machineCode) {
                // Nếu ô mã máy trống → bỏ qua nhóm này
                continue;
            }
    
            // 1) Kiểm tra tồn tại máy
            if (!Machine::where('code', $machineCode)->exists()) {
                throw new Exception("Mã máy ở {$group['machineCol']}{$rowIndex} không tồn tại", 404);
            }
    
            // 2) Tạo/điền thứ tự ưu tiên (priority)
            $previous = MachinePriorityOrder::where('product_id', $productId)
                ->where('line_id', $group['line_id'])
                ->orderByDesc('priority')
                ->value('priority');
            $priority = ((int)$previous) + 1;
    
            $mpo = MachinePriorityOrder::firstOrCreate([
                'product_id' => $productId,
                'line_id'    => $group['line_id'],
                'machine_id' => $machineCode,
            ], [
                'priority'   => $priority,
            ]);
    
            // 3) Lưu spec cho từng cột param nếu không rỗng
            foreach ($group['paramCols'] as $col) {
                $val = trim($row[$col] ?? '');
                if ($val === '') {
                    continue;
                }
                MachineProductionMode::firstOrCreate([
                    'product_id'     => $productId,
                    'machine_id'     => $machineCode,
                    'parameter_name' => $title[$col],
                ], [
                    'standard_value' => $val,
                ]);
            }
        }
        $machinePriorityOrder = null;
        foreach ($row as $key => $value) {
            $line_id = null;
            $machine_id = null;
            if (in_array($key, $this->excelColumnRange("KI", "KN"))) {
                $line_id = 24; //Gap dan lien hoan
                $machine_id = $row['KI'];
            }
            else if (in_array($key, $this->excelColumnRange("KO", "LK"))) {
                $line_id = 25;
                $machine_id = $row['KO'];
            }
            else if (in_array($key, $this->excelColumnRange("LP", "LS"))) {
                $line_id = 27;
                $machine_id = $row['LP'];
            }
            else if (in_array($key, $this->excelColumnRange("LZ", "MD"))) {
                $line_id = 26;
                $machine_id = $row['LZ'];
            }
            
            if ($line_id && $machine_id) {
                $check = Machine::where('code', $machine_id)->exists();
                if (!$check) {
                    throw new Exception("Mã máy ở " . $key . $rowIndex . " không tồn tại", 404);
                }
                $previousMachinePriorityOrder = MachinePriorityOrder::where('product_id', $productId)->where('line_id', $line_id)->orderBy('priority', 'DESC')->first();
                $machinePriorityOrder = MachinePriorityOrder::firstOrCreate([
                    'product_id' => $productId,
                    'line_id' => $line_id,
                    'machine_id' => $machine_id,
                ],
                [
                    'priority' => (int)($previousMachinePriorityOrder->priority ?? 0) + 1,
                ]);
                if ($machinePriorityOrder && trim($value)) {
                    $machineProductionMode = [
                        'product_id' => $productId,
                        'machine_id' => $machine_id,
                        'parameter_name' => $title[$key],
                        'standard_value' => $value
                    ];
                    MachineProductionMode::firstOrCreate($machineProductionMode);
                }
            }
            
        }
        
    }

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

    function insertHeader($sheet, $allData, $parent, $start, $range, $mergedCells, $start_row, $slugArray = [])
    {
        if (count($allData) < $start) {
            return 'break';
        }
        foreach ($range as $key) {
            if ($start === 0) {
                $parent = null;
            }
            $mergeCell = $this->checkHorizontalMergedCell($mergedCells, $key . $start_row);
            if ($mergeCell) {
                $parent_header = ExcelHeader::firstOrCreate([
                    'header_name' => $allData[$start][$key] ?? "",
                    'column_position' => $mergeCell,
                    'section' => null,
                    'parent_id' => $parent->id ?? null,
                    'field_name' => Str::slug($allData[$start][$key] ?? ""),
                ]);
                $next_row_index = $start + 1;
                $next_start_row = $start_row + 1;
                $first_key = preg_replace('/[^a-zA-Z]/', '', explode(':', $mergeCell)[0]);
                $last_key = preg_replace('/[^a-zA-Z]/', '', explode(':', $mergeCell)[1]);
                $first_index = filter_var(explode(':', $mergeCell)[0], FILTER_SANITIZE_NUMBER_INT);
                $last_index = filter_var(explode(':', $mergeCell)[1], FILTER_SANITIZE_NUMBER_INT);
                if ($last_index > $first_index) {
                    $next_row_index += $last_index - $first_index;
                    $next_start_row += $last_index - $first_index;
                }
                $this->insertHeader($sheet, $allData, $parent_header, $next_row_index, $this->excelColumnRange($first_key, $last_key), $mergedCells, $next_start_row, $slugArray);
            } else {
                if (!empty($allData[$start][$key])) {
                    $position = $key . $start_row;
                    $mergeCell = $this->checkVerticalMergedCell($mergedCells, $key . $start_row);
                    if ($mergeCell) {
                        $position = $mergeCell;
                    }
                    $excel_header = ExcelHeader::firstOrCreate([
                        'header_name' => $allData[$start][$key] ?? "",
                        'column_position' => $position,
                        'section' => null,
                        'parent_id' => $parent->id ?? null,
                        'field_name' => ($slugArray[$key] ?? ""),
                    ]);
                    if (!empty($allData[$start + 1][$key])) {
                        $position = $key . ($start_row + 1);
                        $child = ExcelHeader::firstOrCreate([
                            'header_name' => $allData[$start + 1][$key] ?? "",
                            'column_position' => $position,
                            'section' => null,
                            'parent_id' => $excel_header->id ?? null,
                            'field_name' => ($slugArray[$key] ?? ""),
                        ]);
                    }
                }
            }
        }
        return 'done';
    }

    function checkHorizontalMergedCell($mergedCells, $cell)
    {
        foreach ($mergedCells as $cells) {
            // Kiểm tra nếu ô nằm trong vùng hợp nhất
            if ($cell === explode(':', $cells)[0]) {
                // Lấy chỉ số hàng bắt đầu và kết thúc của vùng hợp nhất
                $startRow = filter_var(explode(':', $cells)[0], FILTER_SANITIZE_NUMBER_INT);
                $endRow = filter_var(explode(':', $cells)[1], FILTER_SANITIZE_NUMBER_INT);
                $startCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[0]);
                $endCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[1]);
                // Nếu hàng bắt đầu và kết thúc giống nhau thì ô này là merge cell trên cùng 1 hàng
                if ($startRow === $endRow || $startCol !== $endCol) {
                    return $cells;
                }
            }
        }
        return false;
    }

    function checkVerticalMergedCell($mergedCells, $cell)
    {
        foreach ($mergedCells as $cells) {
            // Kiểm tra nếu ô nằm trong vùng hợp nhất
            if ($cell === explode(':', $cells)[0]) {
                // Lấy chỉ số hàng bắt đầu và kết thúc của vùng hợp nhất
                $startCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[0]);
                $endCol = preg_replace('/[^a-zA-Z]/', '', explode(':', $cells)[1]);
                // Nếu hàng bắt đầu và kết thúc giống nhau thì ô này là merge cell trên cùng 1 hàng
                if ($startCol === $endCol) {
                    return $cells;
                }
            }
        }
        return false;
    }

    function excelColumnRange($start_col, $end_col = null, ...$additional_cols)
    {
        // Nếu không có $end_col, đặt $end_col là $start_col
        if ($end_col === null) {
            $end_col = $start_col;
        }

        $start_num = $this->columnToNumber($start_col);
        $end_num = $this->columnToNumber($end_col);

        $columns = [];
        for ($i = $start_num; $i <= $end_num; $i++) {
            $columns[] = $this->numberToColumn($i);
        }

        // Thêm các cột bất kỳ vào mảng kết quả
        foreach ($additional_cols as $col) {
            if (!in_array($col, $columns)) {
                $columns[] = $col;
            }
        }

        return $columns;
    }

    // Chuyển đổi tên cột Excel thành số thứ tự
    function columnToNumber($col)
    {
        $num = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $num;
    }

    // Chuyển đổi số thứ tự thành tên cột Excel
    function numberToColumn($num)
    {
        $col = '';
        while ($num > 0) {
            $remainder = ($num - 1) % 26;
            $col = chr(65 + $remainder) . $col;
            $num = intval(($num - 1) / 26);
        }
        return $col;
    }

    public function importProductAndCustomer(Request $request)
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');
        $request->validate([
            'file' => 'required|mimes:xlsx',
        ]);
        try {
            Excel::import(new ProductImport(), $request->file('file'));
        } catch (\Exception $e) {
            // Handle the exception and return an appropriate response
            throw $e;
        }

        return $this->success('', 'Import thành công');
    }

    public function convertSpec()
    {
        $lines = Line::where('factory_id', 2)->get();
        ProductionJourney::truncate();
        Product::all()->each(function ($product) use ($lines) {
            ProductCustomer::updateOrCreate(['customer_id' => $product->customer_id, 'product_id' => $product->id]);
            foreach ($lines as $line) {
                $specs = Spec::where('product_id', $product->id)->where('line_id', $line->id)->get()->mapWithKeys(function ($spec) {
                    return [$spec->slug => $spec->value];
                });
                $production_order = $specs['hanh-trinh-san-xuat'] ?? null;
                if ($production_order) {
                    $material_waste = $specs['hao-phi-vao-hang-cac-cong-doan'] ?? null;
                    $line_production_waste = $specs['hao-phi-san-xuat-cac-cong-doan'] ?? null;
                    $prep_time = $specs['chuan-bidau-ca'] ?? null;
                    $transportation_waste = $specs['van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau'] ?? null;
                    $roll_change_time = $specs['thoi-gian-len-xuong-cuon'] ?? null;
                    $input_quantity = $specs['vao-hang-setup-may'] ?? null;
                    $hourly_output = $specs['nang-suat-an-dinhgio'] ?? null;
                    $operator_count = $specs['nhan-su-an-dinh-may-nguoi'] ?? null;
                    ProductionJourney::create([
                        'product_id' => $product->id,
                        'line_id' => $line->id,
                        'production_order' => $production_order,
                        'material_waste' => $material_waste,
                        'line_production_waste' => $line_production_waste,
                        'prep_time' => $prep_time,
                        'transportation_waste' => $transportation_waste,
                        'roll_change_time' => $roll_change_time,
                        'input_quantity' => $input_quantity,
                        'hourly_output' => $hourly_output,
                        'operator_count' => $operator_count,
                    ]);
                }
                $production_order = $specs['hanh-trinh-san-xuat'] ?? null;
            }
        });
    }
}
