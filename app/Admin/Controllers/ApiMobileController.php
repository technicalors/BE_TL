<?php

namespace App\Admin\Controllers;

use App\Helpers\QueryHelper;
use App\Models\CheckSheetWork;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Models\CheckSheetLog;
use App\Models\ErrorMachine;
use Illuminate\Support\Str;
use App\Models\Cell;
use App\Models\CellProduct;
use App\Models\CheckSheet;
use App\Models\Customer;
use App\Models\CustomUser;
use App\Models\Error;
use App\Models\InfoCongDoan;
use App\Models\Insulation;
use App\Models\IOTLog;
use App\Models\MachineIot;
use App\Models\Line;
use App\Models\LineTable;
use App\Models\LogInTem;
use App\Models\LogWarningParameter;
use App\Models\Losx;
use App\Models\Lot;
use App\Models\LotPlan;
use App\Models\LSXLog;
use App\Models\Machine;
use App\Models\MachineParameter;
use App\Models\Product;
use App\Models\WareHouseLog;
use App\Traits\API;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Exception;
use GrahamCampbell\ResultType\Success;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PhpParser\Node\Expr\FuncCall;
use App\Models\MachineLog;
use App\Models\MachineParameterLogs;
use App\Models\MachineParameters;
use App\Models\MachineSpec;
use App\Models\MachineSpeed;
use App\Models\MachineStatus;
use App\Models\MaterialExportLog;
use App\Models\MaterialLog;
use App\Models\Monitor;
use App\Models\OddBin;
use App\Models\ProductOrder;
use App\Models\QCHistory;
use App\Models\Scenario;
use App\Models\Spec;
use App\Models\Stamp;
use App\Models\TestCriteria;
use App\Models\ThongSoMay;
use App\Models\Tracking;
use App\Models\Unit;
use App\Models\WareHouseExportPlan;
use App\Models\Workers;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use SebastianBergmann\CodeUnit\FunctionUnit;
use stdClass;
use Symfony\Polyfill\Intl\Idn\Info;

class ApiMobileController extends AdminController
{
    use API;
    private $user;
    public function __construct(CustomUser $customUser)
    {
        $this->user = $customUser;
    }
    private function parseDataUser($user)
    {
        $permission = [];

        foreach ($user->roles as $role) {
            $tm = ($role->permissions()->pluck("slug"));
            foreach ($tm as $t) {
                $permission[] = $t;
            }
        }

        $data =  [
            "username" => $user->username,
            "name" => $user->name,
            "avatar" => $user->avatar,
            "gender" => $user->gender,
            "email" => $user->email,
            "address" => $user->address,
            "phone" => $user->phone,
            "permission" => $permission,
            "token" => $user->createToken("")->plainTextToken,
        ];
        return $data;
    }

    public function login(Request $request)
    {
        $validate = Validator::make($request->all(), [
            "username" => "required",
            "password" => "required",
        ]);
        if ($validate->fails()) {
            return $this->failure();
        }
        $credentials = $request->only(["username", 'password']);
        if (Admin::guard()->attempt($credentials)) {
            $user = Admin::user();
            $user = $this->user->find($user->id);
            if($user->username !== 'admin'){
                $user->tokens()->delete();
            }
            return $this->success($this->parseDataUser($user), 'Đăng nhập thành công');
        }
        return $this->failure([], 'Sai tên đăng nhập hoặc mật khẩu!');
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if (isset($user))
            $user->tokens()->delete();
        return $this->success();
    }

    public function userInfo(Request $request)
    {
        $user =  $request->user();
        if ($user)
            return $this->success($this->parseDataUser($user));
        return $this->failure([], "Nguời dùng không tồn tại");
    }

    public function userChangePassword(Request $request)
    {
        $user = $request->user();
        if (!$request->password || !$request->newPassword) {
            return $this->failure([], "Mật khẩu cũ và mật khẩu mới là bắt buộc");
        }

        if (Hash::check($request->password, $user->password)) {
            $user->password = Hash::make($request->newPassword);
            $user->save();
            return $this->success($user, 'Đổi mật khẩu thành công');
        }
        return $this->failure([], "Sai mật khẩu, không thể thực hiện thao tác này");
    }

    /* =====================    PLAN   ================*/

    public function overallPlan(Request $request) {}

    /* =====================    END-PLAN   ================*/
    /* =====================    MACHINE  ================*/

    public function listMachine(Request $request)
    {
        $all = Machine::all();

        return $this->success($all);
    }

    public function detailMachine(Request $request)
    {
        $machine = Machine::find($request->machine_id);
        if (!$machine) return $this->failure([], "Machine not found");
        return $this->success($machine->parameter->first());
    }

    public function machineLog()
    {
        // $logs = MachineLog::whereDate('created_at', date('Y-m-d'))->orWhere('info->result', '')->get();
        $logs = MachineLog::all();
        $data = [];
        foreach ($logs as $key => $log) {
            $object = new \stdClass();
            $object->model = $log->machine_id;
            $object->tg_bat_dau = $log->info['start_time'];
            $object->tg_ket_thuc = isset($log->info['end_time']) ? $log->info['end_time'] : 'Chưa cập nhật';
            if ($object->tg_ket_thuc != 'Chưa cập nhật') {
                $object->tg_dung = number_format((strtotime($object->tg_ket_thuc) - strtotime($object->tg_bat_dau)) / 60);
            } else {
                $object->tg_dung = 'Chưa cập nhật';
            }
            $object->so_lan_dung = MachineLog::where('machine_id', $log->machine_id)->whereDate('created_at', date('Y-m-d'))->count();
            if (!isset($log->info['result'])) {
                $object->id = $log->id;
            }
            $data[] = $object;
        }
        return $this->success($data);
    }

    /* =====================  END  MACHINE  ================*/



    /* =====================  API CỦA AN  ================*/

    // lấy list máy của công đoạn
    public function getMachineOfLine(Request $request)
    {
        $query = Machine::orderBy('code', 'ASC');
        if ($request->line_id) {
            $query->where('line_id', $request->line_id);
        }
        if ($request->is_iot) {
            $query->where('is_iot', $request->is_iot);
        }
        $machines = $query->get();
        return $this->success($machines);
    }

    // api TabChecksheet - Màn OI Thiết bị
    public function getChecksheetOfMachine(Request $request)
    {
        $machine_id = $request->machine_id;
        $machine = Machine::where('code', $machine_id)->first();
        if (!$machine) return $this->success();
        $line = Line::find($machine['line_id']);
        if ($line->id == 25) {
            $checksheet_ids = CheckSheet::where('machine_id', $machine_id)->pluck('id');
        } else {
            $checksheet_ids = CheckSheet::where('line_id', $line->id)->pluck('id');
        }
        $checkSheetWork = CheckSheetWork::whereIn('check_sheet_id', $checksheet_ids)->with('checksheet')->get();
        $startDate = date("Y-m-d 00:00:00");
        $endDate = date("Y-m-d 23:59:59");
        $string = '%"machine_id":"' . $machine_id . '"%';
        $logs = CheckSheetLog::where('info', 'like', $string)->whereBetween('created_at', [$startDate, $endDate])->get();
        if (count($logs) == 0) return $this->success([
            "data" => $checkSheetWork,
            "is_checked" => false,
        ]);
        $log = $logs[0];
        foreach ($checkSheetWork as $s) {
            $s['date_time'] = $log['created_at'];
            foreach ($log['info']['data'] as $cs) {
                if ($s['id'] == $cs['id']) $s['value'] = $cs['value'];
            }
        }
        return $this->success([
            "data" => $checkSheetWork,
            "is_checked" => true,
        ]);
    }
    public function lineChecksheetLogSave(Request $request)
    {
        $machine_id = $request->machine_id;
        $data = $request->data;
        $checksheet = CheckSheetLog::where("info->machine_id", $machine_id)->whereDate('created_at', Carbon::today())->first();
        if (!$checksheet) {
            $res = CheckSheetLog::create([
                "info" => [
                    "created_by" => $request->user()->id,
                    "machine_id" => $machine_id,
                    "data" => $data,
                ]
            ]);
        } else {
            $res = $checksheet;
        }
        $res->id = Carbon::now()->timestamp;
        $res->save();
        return $this->success($res);
    }


    // api TabChon lỗi - Màn OI Thiết bị
    public function lineError(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!$line) return $this->success();
        $error = ErrorMachine::whereIn('line_id', [$request->line_id, '0'])->get();
        // format lại data dùng tạm thời
        foreach ($error as $e) {
            $e['name'] = $e['noi_dung'];
            // $e['code'] = $e['code'];
        }
        return $this->success($error);
    }

    public function logsMachine(Request $request)
    {
        $machine_id = $request->machine_id;
        $machine = Machine::where('code', $machine_id)->first();
        if (!$machine) {
            return $this->failure([], 'Không tìm thấy mã máy');
        }
        $logs = MachineLog::where('machine_id', $machine->code)->whereNull('info->error_id')->orderBy('created_at', 'DESC')
            ->get();
        $log_data = [];
        foreach ($logs as $log) {
            $info = $log['info'];
            $start = Carbon::parse($log['info']['start_time'] ?? 'now');
            $end = Carbon::parse($log['info']['end_time'] ?? 'now');
            if ($end->diffInMinutes($start) <= 3) {
                continue;
            }
            $info['start_time'] = $log['info']['start_time'] ? date('d/m/Y H:i:s', $log['info']['start_time']) : '';
            $info['end_time'] =  isset($log['info']['end_time']) ? date('d/m/Y H:i:s', $log['info']['end_time']) : '';
            $log['info'] =  $info;
            // if (!isset($log['info']['error_id'])) $error = null;
            // else {
            //     $error = ErrorMachine::where('id', $log['info']['error_id'])->get()[0];
            //     if($error)
            //     $error['name'] = $error['noi_dung'];
            // };
            // $log['error'] = $error;
            $log['error'] = null;
            $log_data[] = $log;
        }
        // dd($log_data);
        return $this->success($log_data);
    }

    public function logsMachine_save(Request $request)
    {
        $current_machine_log = $request->machine_log;
        $machine_log = MachineLog::find($current_machine_log['id'] ?? '');
        $machine_id = $machine_log->machine_id;
        if (!$machine_log) return $this->failure("Thông tin lỗi máy không đúng!");
        $machine = Machine::where('code', $machine_log['machine_id'])->with('line')->get();
        if (count($machine) > 0) $machine = $machine[0];
        else return $this->failure("Lỗi không có thông tin máy");
        $info = $machine_log->info;
        if (isset($current_machine_log['id_error'])) {
            $info['error_id'] = $current_machine_log['id_error'];
        } else {
            $new_error = new ErrorMachine();
            $new_error->noi_dung = $current_machine_log['name_error'] ?? "";
            // $new_error->code = "";
            $new_error->nguyen_nhan = $current_machine_log['nguyen_nhan_error'] ?? "";
            $new_error->khac_phuc = $current_machine_log['khac_phuc_error'] ?? "";
            $new_error->phong_ngua = $current_machine_log['nguyen_nhan_error'] ?? "";
            $new_error->line_id = $machine['line_id'];
            $new_error->save();
            $info['error_id'] = $new_error['id'];
        }
        $info['user_id'] = $request->user()->id;
        $info['user_name'] = $request->user()->name;
        $machine_log->info = $info;
        $machine_log->save();
        $records = MachineLog::where('machine_id', $machine_id)->get();
        $check = true;
        foreach ($records as $key => $record) {
            if (!isset($record->info['error_id']) && isset($record->info['end_time'])) {
                $check = false;
                break;
            }
        }
        if ($check) {
            Monitor::where('machine_id', $machine_id)->where('type', 'tb')->where('status', 0)->update(['status' => 1]);
        }
        return $this->success($machine_log);
    }

    public function machineOverall(Request $request)
    {
        $machine = Machine::where('code', $request->machine_id)->first();
        if (!$machine) return $this->failure([], 'Không tìm thấy mã máy');
        $logs = MachineLog::where('machine_id', $machine->code)->whereNotNull(['info->start_time', 'info->end_time'])->whereDate('created_at', date('Y-m-d'))->get();
        $tg_dung = 0;
        $so_lan_dung = 0;
        $so_loi = 0;
        foreach ($logs as $log) {
            $tg_dung += $log['info']['end_time'] - $log['info']['start_time'];
            $so_lan_dung += 1;
            $so_loi += (isset($log['info']['type']) && $log['info']['type'] == '1') ? 1 : 0;
        }
        $obj = new stdClass;
        $obj->tg_dung = $tg_dung;
        $obj->so_lan_dung = $so_lan_dung;
        $obj->so_loi = $so_loi;
        return $this->success($obj, '');
    }

    /* =====================  END  UNUSUAL  ================*/

    //LINE

    public function listLine(Request $request)
    {
        $list = Line::where("display", "1")->orderBy('ordering', 'ASC')->get();
        $except = [
            'sx' => ['kho-thanh-pham', 'oqc', 'iqc'],
            'cl' => ['kho-thanh-pham', 'kho-bao-on', 'u']
        ];
        $data = [];
        if (isset($request->type)) {
            if ($request->type === 'tb') {
                foreach ($list as $item) {
                    if (count($item->machine()->where('display', '1')->get()) > 0) {
                        $data[] = [
                            "label" => $item->name,
                            "ordering" => $item->ordering,
                            "value" => $item->id
                        ];
                    }
                }
            } else {
                foreach ($list as $item) {
                    $line_key = Str::slug($item->name);
                    if (in_array($line_key, $except[$request->type])) {
                        continue;
                    }
                    $data[] = [
                        "label" => $item->name,
                        "ordering" => $item->ordering,
                        "value" => $item->id
                    ];
                }
            }
        } else {
            foreach ($list as $item) {
                $data[] = [
                    "label" => $item->name,
                    "ordering" => $item->ordering,
                    "value" => $item->id
                ];
            }
        }


        return $this->success($data);
    }

    public function listMachineOfLine(Request $request)
    {
        $query = Machine::orderBy('code', 'ASC');
        if ($request->line_id) {
            $query->where('line_id', $request->line_id);
        }
        if ($request->is_iot) {
            $query->where('is_iot', $request->is_iot);
        }
        $machines = $query->get();
        return $this->success($machines);
    }

    public function lineOverall(Request $request)
    {
        // if ($request->type == 1)
        $data =  LSXLog::overrallIn($request->line_id);

        return $this->success($data);
    }

    public function lineUser()
    {

        $list = Workers::all();
        return $this->success($list);
    }




    //Dashboard

    public function dashboardGiamSat(Request $request)
    {
        // $line = Line::where('name', 'like', 'in%')->first();
        $machines = [];
        // if ($request->type == 2) {
        //     $line = Line::where('name', 'like', 'gh%')->first();
        // }
        // $machines = Machine::whereHas('line', function ($q) use ($line) {
        //     return $q->where('id', $line->id);
        // })->get();

        $machines = Machine::all();

        $res = [];
        foreach ($machines as $machine) {
            $rq = new \Illuminate\Http\Request();
            $rq->replace(['machine_id' => $machine->id]);
            $info = $this->uiManufacturing($rq, true);
            $info['so_loi'] = rand(1, 8);
            $info['cycle_time'] = rand(800, 1000);
            $info['thoi_gian_dung'] = rand(0, 5);
            $info['ty_le_van_hanh'] = rand(80, 100) . '%';
            $res[] = [
                // "line" => $line->name,
                "line" => $machine->line->name,
                "machine" => $machine->id,
                "info" => $info
            ];
        }
        return $this->success($res);
    }





    public function uploadKHXKT(Request $request)
    {
        $hash = hash_file("md5", $_FILES['file']['tmp_name']);
        $lists = MaterialExportLog::where("file", $hash);
        $lists->delete();
        // get file extension
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
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 5
            if ($key > 6) {
                if (is_null($row['E']) || is_null($row['H'])) {
                    break;
                }
                $record = MaterialExportLog::whereDate('created_at', date('Y-m-d'))->where('material_id', $row['E'])->first();
                if ($record) {
                    $sl_kho_xuat = (int) str_replace(',', '', $row['H']) + $record->sl_kho_xuat;
                    $record->update(['sl_kho_xuat' => $sl_kho_xuat]);
                } else {
                    MaterialExportLog::create(['material_id' => $row['E'], 'sl_kho_xuat' => (int) str_replace(',', '', $row['H']), 'file' => $hash]);
                }
            }
        }
        return $this->success([], 'Upload excel thành công');
    }
    public function storeLot(Request $request)
    {
        $input = $request->all();
        foreach ($input['log'] as $key => $value) {
            $count = 0;
            $plan = ProductionPlan::where('lo_sx', $value['lo_sx'])->first();
            foreach ($value['value_pallet'] as $key => $val) {
                if (!$val['value']) {
                    return $this->failure([], 'Chia pallet không thành công');
                    break;
                } else {
                    $count += $val['value'];
                }
            }
            // if($count > $plan->sl_nvl){
            //     return $this->failure([], 'Số lượng của pallet không được vượt quá số lượng kế hoạch');
            //     break;
            // }
        }
        // if($count <= )
        foreach ($input['log'] as $key => $value) {
            $plan = ProductionPlan::where('lo_sx', $value['lo_sx'])->whereDate('ngay_sx', '>=', date('Y-m-d'))->first();
            foreach ($value['value_pallet'] as $key => $val) {
                $pallet = new Lot();
                $pallet->lo_sx = $value['lo_sx'];
                $pallet->type = 0;
                $pallet->so_luong =  $val['value'];
                $pallet->product_id =  $plan->product->id;
                $pallet->material_export_log_id =  $value['id'];
                $pallet->id =  $pallet->lo_sx . "." . $plan->product->id . ".pl" .  + ($val['key'] + 1);
                $pallet->save();
            }
        }
        // MaterialExportLog::find($value['id'])->update(['status' => 1]);
        return $this->success([], 'Chia pallet thành công');
    }

    public function dashboardMonitor(Request $request)
    {
        $monitors = Monitor::whereDate('created_at', date('Y-m-d'))->orderBy('created_at', 'DESC')->get();
        return $this->success($monitors);
    }

    public function getMonitor(Request $request)
    {
        $trackings = Tracking::whereNotNull('lot_id')->get();
        $machine_not_run_ids = Tracking::where('status', 1)->pluck('machine_id');
        foreach ($trackings as $key => $tracking) {
            $machine = Machine::where('code', $tracking->machine_id)->first();
            $lot = Lot::find($tracking->lot_id);
            $plan = $lot->getPlanByLine($machine->line_id);
            $info_cd = InfoCongDoan::where('lot_id', $tracking->lot_id)->where('line_id', $machine->line_id)->first();
            if ($plan && $plan->thoi_gian_chinh_may) {
                $check_info = InfoCongDoan::where('lot_id', 'like', '%' . $lot->lo_sx . '%')->where('line_id', $machine->line_id)->count();
                if ($check_info == 1) {
                    if (is_null($info_cd->thoi_gian_bam_may)) {
                        $thoi_gian_tt = strtotime(date('Y-m-d H:i:s')) -  strtotime($info_cd->thoi_gian_bat_dau);
                        if ($thoi_gian_tt > (($plan->thoi_gian_chinh_may * 3600) - 60)) {
                            $check = Monitor::where('type', 'sx')->where('machine_id', $machine->code)->where('parameter_id', 1)->where('status', 0)->first();
                            if (!$check) {
                                $monitor = new Monitor();
                                $monitor->type = 'sx';
                                $monitor->parameter_id = 1;
                                $monitor->content = 'Vượt thời gian định mức';
                                $monitor->machine_id = $machine->code;
                                $monitor->status = 0;
                                $monitor->save();
                            }
                        }
                    } else {
                        $check = Monitor::where('type', 'sx')->where('machine_id', $machine->code)->where('parameter_id', 1)->where('status', 0)->first();
                        if ($check) {
                            $time = number_format((strtotime($info_cd->thoi_gian_bam_may) - strtotime($info_cd->thoi_gian_bat_dau) - ($plan->thoi_gian_chinh_may * 3600)) / 60);
                            Monitor::where('type', 'sx')->where('machine_id', $machine->code)->where('parameter_id', 1)->where('status', 0)->update(['status' => 1, 'value' => $time]);
                        }
                        $tg_chay = (strtotime(date('Y-m-d H:i:s')) - strtotime($info_cd->thoi_gian_bam_may));
                        if ($tg_chay > 1800) {
                            $sl_kh = ($plan->sl_thanh_pham * $plan->product->so_bat) / ($plan->thoi_gian_thuc_hien * 3600);
                            $sl_chuan = $sl_kh * $tg_chay;
                            $san_luong = InfoCongDoan::where('lo_sx', $lot->lo_sx)->where('line_id', $machine->line_id)->sum('sl_dau_ra_hang_loat');
                            if ($sl_chuan > $san_luong) {
                                $check_monitor = Monitor::where('type', 'sx')->where('machine_id', $machine->code)->where('parameter_id', 2)->where('status', 0)->first();
                                if (!$check_monitor) {
                                    $monitor = new Monitor();
                                    $monitor->type = 'sx';
                                    $monitor->parameter_id = 2;
                                    $monitor->content = 'Chậm tiến độ sản xuất';
                                    $monitor->machine_id = $machine->code;
                                    $monitor->status = 0;
                                    $monitor->save();
                                }
                            } else {
                                $check_monitor = Monitor::where('type', 'sx')->where('machine_id', $machine->code)->where('parameter_id', 2)->where('status', 0)->first();
                                if ($check_monitor) {
                                    Monitor::where('type', 'sx')->where('machine_id', $machine->code)->where('parameter_id', 2)->where('status', 0)->update(['status' => 1]);
                                }
                            }
                        }
                    }
                }
            }
        }
        Monitor::whereDate('created_at', '<', date('Y-m-d'))->where('type', 'tb')->update(['status' => 1]);
        $data = Monitor::where(function ($query) use ($trackings) {
            $query->whereIn('type', ['sx', 'tb'])->where('status', 0)->whereIn('machine_id', $trackings->pluck('machine_id'))->whereNotIn('machine_id', ['KZY-1020UV', 'BP-01', 'bao-on']);
        })->orWhere(function ($query) use ($machine_not_run_ids) {
            $query->where('type', 'cl')->where('status', 0)->whereIn('machine_id', $machine_not_run_ids)->whereNotIn('machine_id', ['KZY-1020UV', 'BP-01', 'bao-on']);
        })->orderBy('created_at', 'DESC')->get();
        foreach ($data as $key => $value) {
            $value->content = $value->content;
        }
        return $this->success($data);
    }

    public function getMonitorTroubleshoot(Request $request)
    {
        $trackings = Tracking::whereNotNull('lot_id')->get();
        $machine_not_run_ids = Tracking::where('status', 1)->pluck('machine_id');
        $data = Monitor::where(function ($query) use ($trackings) {
            $query->whereNotNull('troubleshoot')->whereIn('type', ['sx', 'tb'])->where('status', 0)->whereIn('machine_id', $trackings->pluck('machine_id'))->whereNotIn('machine_id', ['KZY-1020UV', 'BP-01', 'bao-on']);
        })->orWhere(function ($query) use ($machine_not_run_ids) {
            $query->whereNotNull('troubleshoot')->where('type', 'cl')->where('status', 0)->whereIn('machine_id', $machine_not_run_ids)->whereNotIn('machine_id', ['KZY-1020UV', 'BP-01', 'bao-on']);
        })->orderBy('created_at', 'DESC')->get();
        return $this->success($data);
    }

    public function insertMonitor(Request $request)
    {
        $monitor = Monitor::where('machine_id', $request->machine_id)->orderBy('created_at', 'desc')->first();
        if (!$monitor) {
            $monitor = new Monitor();
        }
        $monitor['type'] = $request->type;
        $monitor['content'] = $request->content;
        $monitor['description'] = $request->description;
        $monitor['machine_id'] = $request->machine_id;
        $monitor['status'] = $request->status ? 1 : 0;
        $monitor['created_at'] = date('Y-m-d H:i:s');
        $monitor->save();
        return $this->success($monitor);
    }


    //PALLET

    public function palletList(Request $request)
    {

        if (!isset($request->id)) {
            return $this->success(Lot::all());
        }
        $pallet = Lot::find($request->id);
        if (!isset($pallet)) return $this->failure([], "Không tìm thấy pallet");
        return $this->success($pallet);
    }



    //Production-Process

    public function scanPallet(Request $request)
    {
        $pallet = Lot::with('product')->find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        if ($pallet->so_luong <= 0) {
            return $this->failure([], "Không có số lượng");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $line_key = Str::slug($line->name);
        $machine_ids = $line->machine->pluck('id');
        $machine_iot_ids = $line->machine->where('is_iot', 1)->where('display', 1)->pluck('id');
        if (count($machine_iot_ids)) {
            $checksheet_logs = CheckSheetLog::whereIn('info->machine_id', $machine_iot_ids)->whereDate('created_at', Carbon::today())->get();
            if (!count($checksheet_logs) > 0) {
                return $this->failure([], "Chưa nhập kiểm tra checksheet");
            }
        }
        // $log = LSXLog::where("lot_id", $pallet->id)->whereDate('created_at', Carbon::today())->get();
        $log = LSXLog::where("lot_id", $pallet->id)->first();

        if ($pallet->type === 0) {
            if ($log) {
                if ($line_key === 'kho-bao-on') {
                    if (!isset($log->info['qc']['iqc'])) {
                        return $this->failure([], "Chưa kiểm tra NVL");
                    } else {
                        $result = array_column(array_intersect_key($log->info['qc']['iqc'], array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
                        if (in_array(0, $result)) {
                            return $this->failure([], "Pallet bị NG");
                        } else if (count($result) < TestCriteria::where('line_id', 23)->get()->groupBy('chi_tieu')->count()) {
                            return $this->failure([], "Chưa hoàn thành kiểm tra NVL");
                        }
                    }
                }
                if (!in_array($line_key, ['chon', 'kho-thanh-pham', 'kho-bao-on']) && !isset($log->info['kho-bao-on']['input']['do_am_giay'])) {
                    return $this->failure([], "Không đủ điều kiện thời gian, độ ẩm");
                }
            } else {
                if ($line_key !== 'kho-bao-on' && $line_key !== 'chon') {
                    return $this->failure([], 'Thực hiện bảo ôn trước');
                }
                if ($line_key === 'kho-bao-on') {
                    return $this->failure([], "Chưa kiểm tra NVL");
                }
            }
        }
        if (!$log) {
            $log = new LSXLog();
            $log->lot_id = $pallet->id;
            $log->info = [];
            $log->save();
        }
        $machine = Machine::whereIn('id', $machine_ids)->where('display', 1)->get()->pluck('code');
        $tracking = Tracking::whereIn('machine_id', $machine)->get();
        foreach ($tracking as $record) {
            if (is_null($record->lot_id) || $record->lot_id === "" || $record->lot_id === $pallet->id) {
                $record['lot_id'] = $pallet->id;
                $record->save();
            } else {
                return $this->failure([], 'Đang có lot chạy trên máy, không thể chạy lot khác');
                break;
            }
        }

        //check bat
        $bats = Lot::where('p_id', $pallet->id)->where('type', 1)->get();
        // return ($bats);
        if ($line_key === 'gap-dan' && count($bats) <= 0) {
            $soluong = (int)($pallet->so_luong) / $pallet->plan->product->so_bat;
            $b1 = new Lot();
            $b1->so_luong = $soluong;
            $b1->type = 1;
            $b1->id = $pallet->id . ".B" . (count($bats) + 1);
            $b1->lo_sx = $pallet->lo_sx;
            $b1->product_id = $pallet->product->id;
            $b1->p_id = $pallet->id;
            $b1->save();

            $bat_log = new LSXLog();
            $bat_log->lot_id = $b1->id;
            $bat_log->save();

            $info_cd_bat = new InfoCongDoan();
            $info_cd_bat->type = 'sx';
            $info_cd_bat->lo_sx = $b1->lo_sx;
            $info_cd_bat->lot_id = $b1->id;
            $info_cd_bat->line_id = $line->id;
            $info_cd_bat->sl_dau_vao_hang_loat = $soluong;
            $info_cd_bat->sl_dau_ra_hang_loat = $soluong;
            $info_cd_bat->product_id = $b1->product_id;
            $info_cd_bat->save();
        }

        $info = $log->info;

        if (!isset($log->info[$line_key])) { //scan vào công đoan
            $machines = $line->machine;
            foreach ($machines as $machine) {
                MachineStatus::reset($machine->code);
            }
            if ($line_key == 'chon') {
                $info[$line_key] = [
                    "thoi_gian_vao" => Carbon::now(),
                    "user_id" => $request->user()->id,
                    "user_name" => $request->user()->name,
                    "sl_in_tem" => 0,
                ];
            } else {
                $info[$line_key] = [
                    "thoi_gian_vao" => Carbon::now(),
                    "user_id" => $request->user()->id,
                    "user_name" => $request->user()->name,
                ];
            }
        }
        $log->info = $info;
        $log->save();

        $info_cong_doan = InfoCongDoan::where("lot_id", $pallet->id)->where('line_id', $request->line_id)->first();
        if (!$info_cong_doan) {
            $info_cong_doan = new InfoCongDoan();
            $info_cong_doan->type = 'sx';
            $info_cong_doan->lot_id = $pallet->id;
            $info_cong_doan->lo_sx = $pallet->lo_sx;
            $info_cong_doan->product_id = $pallet->product_id;
            if ($line_key === 'in') {
                $tracking = Tracking::where('machine_id', 'GL_637CIR')->first();
                $info_cong_doan->start_powerM = $tracking->powerM;
            }
            if ($line_key === 'kho-bao-on' || $line_key === 'u') {
                $info_cong_doan->sl_dau_vao_hang_loat = ($pallet->so_luong * $pallet->product->so_bat);
            }
            $info_cong_doan->save();
        }

        if ($line_key === 'in-luoi' || $line_key === 'boc' && !$info_cong_doan->sl_dau_vao_hang_loat) {
            $info_cong_doan->sl_dau_vao_hang_loat = $pallet->so_luong;
        }
        if ($line_key === 'chon' && !$info_cong_doan->sl_dau_vao_hang_loat) {
            $info_cong_doan->sl_dau_vao_hang_loat = $pallet->so_luong;
        }
        if (!isset($info_cong_doan->line_id)) {
            $info_cong_doan->line_id = $request->line_id;
            $info_cong_doan->thoi_gian_bat_dau = Carbon::now();
        }
        if ($line_key === 'kho-bao-on' && !isset($info_cong_doan->line_id)) {
            $info_cong_doan->thoi_gian_ket_thuc = Carbon::now();
        }
        $info_cong_doan->save();
        return $this->success($log);
    }

    //

    public function inTem(Request $request)
    {
        return $this->endIntem($request);
    }

    //
    public function endIntem($request)
    {
        $lot_id = $request->lot_id;
        $line_id = $request->line_id;
        $is_pass = $request->is_pass ? $request->is_pass : false;
        $pallet = Lot::with('product')->find($lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }

        $line = Line::find($line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        # cong doan pallet mới => không có lsx_log;
        $line_key = Str::slug($line->name);
        $log = $pallet->log;

        $info = $log->info;
        // $log_in_tem = LogInTem::where('lot_id', $pallet->id)->where('line_id', $line->id)->where('type', 1)->get();
        if (!$is_pass) {
            if ((!in_array($line_key, ['kho-bao-on', 'u']) && !$log->checkQC($line_key))) return $this->failure([], "Bạn chưa thể in tem, cần kiểm tra lại chất lượng");
            if ($line_key === 'kho-bao-on' && !isset($log->info['kho-bao-on']['input']['do_am_giay']) && $pallet->type === 0) {
                return $this->failure([], "Không đủ điều kiện thời gian, độ ẩm");
            }
        }
        if ($line_key !== 'gap-dan' && $line_key != 'chon') {
            if (isset($info['qc']) && isset($info['qc'][$line_key])) {
                $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
            }
            $info[$line_key]['thoi_gian_ra'] = Carbon::now();
            $info[$line_key]['thoi_gian_xuat_kho'] = Carbon::now();
        }
        if ($line_key == 'gap-dan') {
            if (!isset($info[$line_key]['bat'])) {
                $info[$line_key]['bat'] = [];
            }
            $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
            $info[$line_key]['bat'][$bat->id] = [
                "thoi_gian_ra" => Carbon::now()
            ];

            //Chia thùng

            $info_cong_doan = InfoCongDoan::where("line_id", $line->id)->where("lot_id", $pallet->id)->first();
            $bats = $info["qc"][$line_key]['bat'];
            if (!array_key_exists($bat->id, $bats)) {
                return $this->failure([], "Bạn chưa thể in tem, cần kiểm tra lại chất lượng");
            }
            $order_line = array_reverse([10, 22, 11, 12, 14]); //In, In lưới, Phủ, Bế, Bóc
            $info_cds = null;
            foreach ($order_line as $l) {
                $prev_info_cd = InfoCongDoan::where("lot_id", $pallet->id)->where("line_id", $l)->first();
                if ($prev_info_cd) {
                    $info_cds = $prev_info_cd;
                    break;
                }
            }
            $soluong = $pallet->so_luong;

            if ((count($bats) + 1) <= $pallet->product->so_bat) {
                $t1 = new Lot();
                $t1->so_luong = $soluong / $pallet->product->so_bat;
                $t1->type = 1;
                $t1->id = $pallet->id . ".B" . (count($bats) + 1);
                $t1->lo_sx = $pallet->lo_sx;
                $t1->product_id = $pallet->product->id;
                $t1->p_id = $pallet->id;
                $t1->save();

                $bat_log = new LSXLog();
                $bat_log->lot_id = $t1->id;
                $bat_log->info = [];
                $bat_log->save();

                $info_cd_bat = new InfoCongDoan();
                $info_cd_bat->type = 'sx';
                $info_cd_bat->sl_dau_ra_hang_loat = $soluong / $pallet->product->so_bat;
                $info_cd_bat->sl_dau_vao_hang_loat = $soluong / $pallet->product->so_bat;
                $info_cd_bat->lot_id = $t1->id;
                $info_cd_bat->lo_sx = $t1->lo_sx;
                $info_cd_bat->line_id = $line->id;
                $info_cd_bat->product_id = $pallet->product_id;
                $info_cd_bat->save();
            }
            $count = count($info[$line_key]['bat']);
            if ($count === $pallet->product->so_bat) {
                $info[$line_key]['thoi_gian_ra'] = Carbon::now();
                $info[$line_key]['thoi_gian_xuat_kho'] = Carbon::now();
                if (isset($info['qc']) && isset($info['qc'][$line_key])) {
                    $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
                }
                $info_cong_doan['thoi_gian_ket_thuc'] = Carbon::now();
                $info_cong_doan->save();
                $pallet['so_luong'] = ($info_cong_doan->sl_dau_ra_hang_loat - $info_cong_doan->sl_tem_vang - $info_cong_doan->sl_ng) / $pallet->product->so_bat;
                $pallet->save();
            }
        }
        if ($line_key == "chon") {
            // $sl_thuc_te = 0;
            $sl_thuc_te_ok = 0;
            if (!isset($info["chon"]['table'])) {
                return $this->failure([], 'Chưa ghi nhận giao việc');
            }
            $tables = $info["chon"]['table'];
            foreach ($tables as $tb) {
                if ((!isset($tb["so_luong_thuc_te_ok"]) || !$tb["so_luong_thuc_te_ok"]) || (!isset($tb["so_luong_thuc_te"]) || !$tb["so_luong_thuc_te"])) {
                    return $this->failure([], 'Chưa hoàn thành ghi nhận số lượng thực tế ok');
                }
                $sl_thuc_te_ok += (int)$tb['so_luong_thuc_te_ok'];
                // $sl_thuc_te += (int)$tb['so_luong_thuc_te'] ?? 0;
            }
            $info_cong_doan = $pallet->infoCongDoan()->where("line_id", 15)->first();
            if (isset($info_cong_doan)) {
                $sl_pallet = $info_cong_doan->sl_dau_vao_hang_loat - ($sl_thuc_te_ok + $info_cong_doan->sl_ng);
                $pallet['so_luong'] = $sl_pallet;
                $pallet->save();
                // $info_cong_doan->sl_dau_vao_hang_loat = $sl_thuc_te;
                $info_cong_doan->sl_dau_ra_hang_loat = $sl_thuc_te_ok + $info_cong_doan->sl_ng;
                $info_cong_doan->save();
            }
            $sl_ok = $sl_thuc_te_ok - $info["chon"]['sl_in_tem'];

            # Chia thùng theo bát, phần thừa lưu vào table
            $dinh_muc = $pallet->product->dinh_muc_thung;
            $length = ceil($request->sl_in_tem / $dinh_muc);
            # chia thùng công đoạn chọn
            $child = clone $pallet;
            $pallet  = $child->parrent;
            $new_id = [];
            $new_sl = [];
            $check_cd = InfoCongDoan::where('line_id', 20)->where('lot_id', $pallet->id)->first();
            if ($check_cd && $check_cd->sl_tem_vang > 0) {
                array_push($new_id, $pallet->id);
                array_push($new_sl, $request->sl_in_tem);
            } else {
                for ($i = 0; $i < $length; $i++) {
                    try {
                        $thung = Lot::where('type', 2)->where('p_id', $pallet->id)->get();
                        $t1 = new Lot();
                        if ($i == $length - 1) {
                            $t1->so_luong = $request->sl_in_tem - ($dinh_muc * $i);
                        } else {
                            $t1->so_luong = $dinh_muc;
                        }
                        $t1->type = 2;
                        $t1->id = $child->id . "-T" . (count($thung) + 1);
                        $t1->lo_sx = $pallet->lo_sx;
                        $t1->product_id = $pallet->plan->product->id;
                        $t1->p_id = $pallet->id;
                        $t1->save();
                        array_push($new_id, $child->id . "-T" . (count($thung) + 1));
                        array_push($new_sl, $t1->so_luong);

                        $t_log = new LSXLog();
                        $t_log->lot_id = $t1->id;
                        $t_log->info = [];
                        $t_log->save();
                    } catch (Exception $ex) {
                    }
                }
            }
            ## phần dư 
            $odd_bin = OddBin::where('product_id', $pallet->product_id)->where('lo_sx', $pallet->lo_sx)->first();
            if (!$odd_bin) {
                $odd_bin = new OddBin();
                $odd_bin->product_id = $pallet->product_id;
                $odd_bin->lo_sx = $pallet->lo_sx;
                $odd_bin->so_luong = 0;
                $odd_bin->save();
            }
            if ($sl_ok < $request->sl_in_tem) {
                $sl_con_lai = $odd_bin->so_luong - ($request->sl_in_tem - $sl_ok);
                $odd_bin->so_luong = $sl_con_lai;
                $odd_bin->save();
            } elseif ($sl_ok > $request->sl_in_tem) {
                $sl_con_lai = $odd_bin->so_luong + ($sl_ok - $request->sl_in_tem);
                $odd_bin->so_luong = $sl_con_lai;
                $odd_bin->save();
            }
            $info["chon"]['sl_in_tem'] = $info["chon"]['sl_in_tem'] + $sl_ok;
            if ($child->so_luong <= 0) {
                $info[$line_key]['thoi_gian_ra'] = Carbon::now();
                if (isset($info['qc']) && isset($info['qc'][$line_key])) {
                    $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
                }
                $info[$line_key]['thoi_gian_xuat_kho'] = Carbon::now();
                $info_cong_doan['thoi_gian_ket_thuc'] = Carbon::now();
                $info_cong_doan->save();
            }
        }
        $log->info = $info;
        $log->save();
        $plan = $pallet->plan;
        $info_cong_doan = InfoCongDoan::where("lot_id", $pallet->id)->where('line_id', $line_id)->first();
        if ($info_cong_doan && $line_key !== 'gap-dan') {
            if ($line_id == '10') {
                $tracking = Tracking::where('machine_id', 'GL_637CIR')->first();
                $info_cong_doan['end_powerM'] = $tracking->powerM;
                $info_cong_doan['powerM'] = $tracking->powerM - $info_cong_doan['start_powerM'];
            }
            if ($line_key === 'kho-bao-on' || $line_key === 'u') {
                $info_cong_doan['sl_dau_ra_hang_loat'] = ($pallet->so_luong * $pallet->product->so_bat);
            }
            $info_cong_doan['thoi_gian_ket_thuc'] = Carbon::now();
            $info_cong_doan->save();
            if ($line_key !== 'kho-bao-on' && $line_key !== 'u') {
                $pallet['so_luong'] = $info_cong_doan->sl_dau_ra_hang_loat - $info_cong_doan->sl_tem_vang - $info_cong_doan->sl_ng;
                $pallet->save();
            }
        }
        $machines = $line->machine;
        foreach ($machines as $item) {
            if ($line_key == 'gap-dan') {
                $ll = $pallet->log;
                $bats = $ll->info['qc']['gap-dan']['bat'];
                if (count($bats) === $plan->product->so_bat ?? 0) {
                    MachineStatus::deactive($item->code);
                    Tracking::where('machine_id', $item->code)->update(['lot_id' => null]);
                }
            } else {
                MachineStatus::deactive($item->code);
                Tracking::where('machine_id', $item->code)->update(['lot_id' => null]);
            }
            Monitor::where('type', 'sx')->where('machine_id', $item->code)->where('parameter_id', 2)->where('status', 0)->update(['status' => 1]);
        }

        if (!isset($length)) $length = 1;

        $res = ["log" => $log, "sl_tem_can_in" => (int) $length, "lot_id" => $pallet];

        $res['lot_id'] = [$pallet->id];
        $res['so_luong'] = [$plan ? $pallet->so_luong / $plan->product->so_bat : 0];
        if ($line_key === 'gap-dan') {
            $id_gd = [];
            $sl_gd = [];
            $info_cong_doan_bat = InfoCongDoan::where("lot_id", $bat->id)->where('line_id', $line_id)->first();
            $bat->so_luong = $info_cong_doan_bat->sl_dau_ra_hang_loat - $info_cong_doan_bat->sl_ng - $info_cong_doan_bat->sl_tem_vang;
            $bat->save();
            if ($info_cong_doan_bat) {
                $sl_gd[] = $bat->so_luong;
                $id_gd[] = $bat->id;
                $res['lot_id'] = $id_gd;
                $res['so_luong'] = $sl_gd;
            }
        }

        if ($line_key == "chon") {
            $res['lot_id'] = $new_id;
            $res['so_luong'] = $new_sl;
        }

        // $new_log_in_tem = new LogInTem();
        // $new_log_in_tem['lot_id'] = $pallet->id;
        // $new_log_in_tem['line_id'] = $line->id;
        // $new_log_in_tem['log'] = $res;
        // $new_log_in_tem['type'] = 1;
        // $new_log_in_tem->save();
        return $this->success($res);
    }


    private function gopThung($child, $dinh_muc, $pallet, $final)
    {
        $data = [];
        $q = OddBin::where("lot_id", $pallet->id)->where('so_luong', '>', 0);
        $bins = $q->get();
        $sum = $q->sum("so_luong");
        $cur_sum = 0;
        $name = $pallet->id . ".B";
        $tv = '';
        if ($child->type == '3') {
            $tv = '.TV13';
        }
        if ($final) {
            $length = ceil($sum / $dinh_muc);
            if ($length > 0) {
                foreach ($bins as $k => $bin) {
                    $name = $name . ".$bin->so_bat";
                }
                for ($i = 0; $i < $length; $i++) {
                    $thung = Lot::where('type', 2)->where('p_id', $pallet->id)->get();
                    $t1 = new Lot();
                    if ($i == $length - 1) {
                        $t1->so_luong = $sum - ($dinh_muc * $i);
                    } else {
                        $t1->so_luong = $dinh_muc;
                    }
                    $t1->type = 2;
                    $t1->id = $name . $tv . "-T" . (count($thung) + 1);
                    $t1->lo_sx = $pallet->lo_sx;
                    $t1->product_id = $pallet->plan->product->id;
                    $t1->p_id = $pallet->id;
                    $t1->save();
                    $data[] = $t1;
                }
            }
            OddBin::where("lot_id", $pallet->id)->delete();
            return $data;
        }
        if ($sum < $dinh_muc) {
            return $data;
        }
        foreach ($bins as $bin) {
            if ($cur_sum + $bin->so_luong < $dinh_muc) {
                $cur_sum += $bin->so_luong;
                $bin->so_luong = 0;
                $name = $name . ".$bin->so_bat";
                $bin->save();
            } else {
                if ($bin->so_luong >= $dinh_muc) {
                    $name = $name . $bin->so_bat;
                } elseif ($cur_sum < $dinh_muc) {
                    $name = $name . ".$bin->so_bat";
                }
                $bin->so_luong = $bin->so_luong -  ($dinh_muc - $cur_sum);
                $cur_sum = $dinh_muc;
                $bin->save();
            }
        }
        $thung = Lot::where('type', 2)->where('p_id', $pallet->id)->get();
        $t1 = new Lot();
        $t1->so_luong = $dinh_muc;
        $t1->type = 2;
        $t1->id = $name . $tv . "-T" . (count($thung) + 1);
        $t1->lo_sx = $pallet->lo_sx;
        $t1->product_id = $pallet->plan->product->id;
        $t1->p_id = $pallet->id;
        $t1->save();
        $data[] = $t1;
        return $data;
    }



    public function inputPallet(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }

        $log = $pallet->log;
        $info = $log->info;
        $line_key = Str::slug($line->name);
        if (!isset($info[$line_key]["input"])) {
            $input = [];
        } else {
            $input = $info[$line_key]["input"];
        }
        $insulation = Insulation::find(1);
        $inp = $request->all();
        $inp['t_ev'] = $insulation->t_ev;
        $inp['e_hum'] =  $insulation->e_hum;
        $info[$line_key]["input"] =  array_merge($input, $inp);
        // $info[$line_key]["input"] =  [];
        $log->info = $info;
        $log->save();
        return $this->success($log);
    }

    private function danhSachPalletBaoOn()
    {
        $data = [];
        //  $list = LSXLog::listPallet('kho-bao-on')->get();
        $now  = date('Y-m-d', strtotime('-10 day'));
        $list = InfoCongDoan::with('lot.product', 'lot.plan', 'log')->where('line_id', 9)->whereDate('thoi_gian_bat_dau', '>=', $now)->orderBy('thoi_gian_bat_dau', 'DESC')->get();
        $insulation = Insulation::find(1);
        foreach ($list as $item) {
            if (isset($item->lot)) {
                if ($item->thoi_gian_ket_thuc) {
                    $daxuat = $item->lot->so_luong;
                    $so_luong_con_lai = 0;
                } else {
                    $daxuat = 0;
                    $so_luong_con_lai = $item->lot->so_luong;
                }

                if ($item->thoi_gian_ket_thuc) {
                    $status = 3;
                }
                $startTime = $item->thoi_gian_bat_dau;
                $startTime = new Carbon($startTime);
                $now = Carbon::now();
                $cnt = $now->diffInHours($startTime);
                $t = filter_var($item->lot->product->thoi_gian_bao_on, FILTER_SANITIZE_NUMBER_INT);
                if ($cnt < $t) $status = 2;
                if (!isset($status)) {
                    $status = 1;
                }
                $record =   [
                    "lo_sx" => $item->lot->lo_sx,
                    "lot_id" => $item->lot->id,
                    "ma_hang" => $item->lot->product->id,
                    "ten_sp" => $item->lot->product->name,
                    "dinh_muc" => $item->sl_dau_vao_hang_loat ?? 0,
                    "sl_ke_hoach" => $item->lot->plan ? $item->lot->plan->sl_nvl : 0,
                    "thoi_gian_bat_dau" => $item->thoi_gian_bat_dau ?? "",
                    "thoi_gian_bao_on" => "",
                    "thoi_gian_bao_on_tieu_chuan" => $item->lot->product->thoi_gian_bao_on === '-' ? 0 : filter_var($item->lot->product->thoi_gian_bao_on, FILTER_SANITIZE_NUMBER_INT),
                    "do_am_phong" => (isset($item->thoi_gian_ket_thuc) && isset($item->log->info['kho-bao-on']['input']["e_hum"])) ? $item->log->info['kho-bao-on']['input']["e_hum"] : $insulation->e_hum,
                    "nhiet_do_phong" => (isset($item->log->info['kho-bao-on']["thoi_gian_xuat_kho"]) && isset($item->log->info['kho-bao-on']['input']["t_ev"])) ? $item->log->info['kho-bao-on']['input']["t_ev"] : $insulation->t_ev,
                    "do_am_phong_tieu_chuan" => $item->lot->product->do_am_phong,
                    "do_am_giay" => isset($item->log->info['kho-bao-on']['input']['do_am_giay']) ? $item->log->info['kho-bao-on']['input']['do_am_giay'] : "",
                    "do_am_giay_tieu_chuan" => $item->lot->product->do_am_giay,
                    "thoi_gian_xuat_kho_bao_on" => $item->thoi_gian_ket_thuc ?? "",
                    "sl_da_xuat" => $item->sl_dau_ra_hang_loat ?? 0,
                    "sl_con_lai" => $so_luong_con_lai,
                    "uph_an_dinh" => $item->lot->plan->UPH ?? 0,
                    "uph_thuc_te" => "",
                    "status" => $status,

                ];
                try {
                    $min = (int)filter_var(explode("~", $item->lot->product->do_am_giay)[0], FILTER_SANITIZE_NUMBER_INT);
                    $max = (int) filter_var(explode("~", $item->lot->product->do_am_giay)[1], FILTER_SANITIZE_NUMBER_INT);
                    $record['do_am_giay_max'] = $max;
                    $record['do_am_giay_min'] = $min;
                } catch (Exception $ex) {
                }
                $data[] = $record;
            }
        }
        return $data;
    }

    private function danhSachPalletU()
    {
        $data = [];
        $now  = date('Y-m-d', strtotime('-5 day'));
        $list = InfoCongDoan::with('lot.product', 'lot.plan', 'lot.log')->where('line_id', 21)->whereDate('thoi_gian_bat_dau', '>=', $now)->orderBy('thoi_gian_bat_dau', 'DESC')->get();
        $insulation = Insulation::find(1);
        foreach ($list as $item) {
            if (isset($item->lot)) {
                if ($item->thoi_gian_ket_thuc) {
                    $daxuat = $item->lot->so_luong;
                    $so_luong_con_lai = 0;
                } else {
                    $daxuat = 0;
                    $so_luong_con_lai = $item->lot->so_luong;
                }

                if ($item->thoi_gian_ket_thuc) {
                    $status = 3;
                }
                $startTime = $item->thoi_gian_bat_dau;
                $startTime = new Carbon($startTime);
                $now = Carbon::now();
                $cnt = $now->diffInHours($startTime);
                $t = filter_var($item->lot->product->thoi_gian_u, FILTER_SANITIZE_NUMBER_INT);
                if ($cnt < $t) $status = 2;
                if (!isset($status)) {
                    $status = 1;
                }
                $record =   [
                    "lo_sx" => $item->lot->lo_sx,
                    "lot_id" => $item->lot->id,
                    "ma_hang" => $item->lot->product->id,
                    "ten_sp" => $item->lot->product->name,
                    "dinh_muc" => $item->sl_dau_vao_hang_loat ?? 0,
                    "sl_ke_hoach" => $item->lot->plan ? $item->lot->plan->sl_nvl : 0,
                    "thoi_gian_bat_dau" => $item->thoi_gian_bat_dau ?? "",
                    "thoi_gian_u" => "",
                    "thoi_gian_u_tieu_chuan" => $item->lot->product->thoi_gian_u === '-' ? 0 : filter_var($item->lot->product->thoi_gian_u, FILTER_SANITIZE_NUMBER_INT),
                    "do_am_phong" => (isset($item->thoi_gian_ket_thuc) && isset($log['u']['input']["e_hum"])) ? $item->lot->log->info['u']['input']["e_hum"] : $insulation->e_hum,
                    "nhiet_do_phong" => (isset($item->lot->log->info['u']["thoi_gian_xuat_kho"]) && isset($item->lot->log->info['u']['input']["t_ev"])) ? $item->lot->log->info['u']['input']["t_ev"] : $insulation->t_ev,
                    "do_am_phong_tieu_chuan" => $item->lot->product->do_am_phong,
                    "do_am_giay" => isset($item->lot->log->info['u']['input']['do_am_giay']) ? $item->lot->log->info['u']['input']['do_am_giay'] : "",
                    "do_am_giay_tieu_chuan" => $item->lot->product->do_am_giay,
                    "thoi_gian_xuat_kho_u" => $item->thoi_gian_ket_thuc ?? "",
                    "sl_da_xuat" => $item->sl_dau_ra_hang_loat ?? 0,
                    "sl_con_lai" => $so_luong_con_lai,
                    "uph_an_dinh" => $item->lot->plan->UPH ?? 0,
                    "uph_thuc_te" => "",
                    "status" => $status,

                ];
                try {
                    $min = (int)filter_var(explode("~", $item->lot->product->do_am_giay)[0], FILTER_SANITIZE_NUMBER_INT);
                    $max = (int) filter_var(explode("~", $item->lot->product->do_am_giay)[1], FILTER_SANITIZE_NUMBER_INT);
                    $record['do_am_giay_max'] = $max;
                    $record['do_am_giay_min'] = $min;
                } catch (Exception $ex) {
                }
                $data[] = $record;
            }
        }
        return $data;
    }

    private function danhSachPalletIn2Chon($line_id)
    {
        $line_arr = [10, 11, 22, 12, 14];
        $line = Line::find($line_id);
        $records = [];
        $now  = date('Y-m-d', strtotime('-5 day'));
        $list = InfoCongDoan::with('lot.product', 'plan', 'lot.log')->with(['spec' => function ($query) {
            $query->where('name', 'Hao phí sản xuất các công đoạn (%)');
        }])->where('line_id', $line_id)->whereDate('thoi_gian_bat_dau', '>=', $now)->orderBy('thoi_gian_bat_dau', 'DESC')->get();
        $spec = Spec::whereIn('product_id', $list->pluck('product_id')->toArray())
            ->select('value', 'product_id', 'line_id', 'slug')->get()
            ->keyBy(function ($item) {
                return $item->product_id . $item->line_id . $item->slug;
            });
        foreach ($list as $item) {
            $plan = $item->lot->getPlanByLine($line_id);
            if (!$plan) {
                $plan = $item->plan;
            }
            $hao_phi_sx = $spec[$item->product_id . $item->line_id . 'hao-phi-san-xuat-cac-cong-doan'] ?? null;
            $hao_phi_vao_hang = $spec[$item->product_id . $item->line_id . 'hao-phi-vao-hang-cac-cong-doan'] ?? null;
            $data =  [
                "lo_sx" => $item->lot->lo_sx,
                "lot_id" => $item->lot->id,
                "ma_hang" => $item->lot ? $item->lot->product->id : '',
                "ten_sp" => $item->lot ? $item->lot->product->name : '',
                "dinh_muc" => $item->lot ? $item->lot->product->dinh_muc : '',
                "sl_ke_hoach" => $plan->sl_nvl ?? 0,
                'thoi_gian_bat_dau_kh' => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_bat_dau)) : "",
                'thoi_gian_bat_dau' => $item->thoi_gian_bat_dau ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_bat_dau)) : "",
                "thoi_gian_ket_thuc_kh" => $item->plan ? date('d/m/Y H:i:s', strtotime($item->plan->thoi_gian_ket_thuc)) : "",
                "",
                'thoi_gian_ket_thuc' => $item->thoi_gian_ket_thuc ? date('d/m/Y H:i:s', strtotime($item->thoi_gian_ket_thuc)) : "",
                'sl_dau_vao_kh' => $plan ? $plan->sl_nvl ?? $plan->sl_giao_sx : 0,
                'sl_dau_ra_kh' =>  $plan ? ($plan->sl_thanh_pham ? $plan->sl_thanh_pham : $plan->sl_giao_sx) : 0,
                'sl_dau_vao' => "",
                'sl_dau_ra' => "",
                "sl_dau_ra_ok" => "",
                "sl_tem_vang" => "",
                "sl_tem_ng" => "",
                "ti_le_ht" => "",
                "uph_an_dinh" => $plan->UPH ?? "",
                "uph_thuc_te" => "",
                "status" => (int)!is_null($item->thoi_gian_ket_thuc),
                "nguoi_sx" => $item->lot->log->info[str::slug($line->name)]['user_name'] ?? "",
                "thoi_gian_bam_may" => $item->thoi_gian_bam_may,
                'hao_phi_cong_doan' => $hao_phi_sx ? $hao_phi_sx->value . "%" : "",
            ];
            if (in_array($line_id, $line_arr)) {
                $data['sl_dau_vao'] = $item->lot->product->so_bat > 0 ? $item->sl_dau_vao_hang_loat / $item->lot->product->so_bat : $item->sl_dau_vao_hang_loat;
                $data['sl_dau_ra'] = $item->lot->product->so_bat > 0 ? $item->sl_dau_ra_hang_loat / $item->lot->product->so_bat : $item->sl_dau_ra_hang_loat;
                $data['sl_tem_vang'] = $item->lot->product->so_bat > 0 ? $item->sl_tem_vang / $item->lot->product->so_bat : $item->sl_tem_vang;
                $data['sl_tem_ng'] = $item->lot->product->so_bat ? $item->sl_ng / $item->lot->product->so_bat : $item->sl_ng;
                // $data['sl_dau_ra_kh'] = $plan->sl_thanh_pham ?? '';
            } else {
                $data['sl_dau_vao'] = $item->sl_dau_vao_hang_loat;
                $data['sl_dau_ra'] = $item->sl_dau_ra_hang_loat;
                $data['sl_tem_vang'] = $item->sl_tem_vang;
                $data['sl_tem_ng'] = $item->sl_ng;
            }
            $data['sl_dau_ra_ok'] = $data['sl_dau_ra'] - $data['sl_tem_vang'] - $data['sl_tem_ng'];
            try {
                $machine = $line->machine[0];
                $status = MachineStatus::getRecord($machine->code);
                $now = Carbon::now();
                $start = new Carbon($status->updated_at);
                $d_time = $now->diffInMinutes($start) + 1;
                if (in_array($line_id, $line_arr)) {
                    $upm = $plan ? (int)($item->sl_dau_ra_hang_loat / ($d_time * $plan->product->so_bat)) : 0;
                } else {
                    $upm = (int)($item->sl_dau_ra_hang_loat / $d_time);
                }
                $data['uph_thuc_te'] = $upm * 60;
            } catch (Exception $ex) {
            }
            $data['ti_le_ht'] = $data['sl_dau_ra_kh'] ? (int) (100 * (($data['sl_dau_ra_ok'] + $data['sl_tem_vang']) / $data['sl_dau_ra_kh'])) : '-';
            if ($line_id == 15) {
                try {
                    if ($data['sl_dau_ra_kh'] > 0)
                        $data['ti_le_ht'] = (int) (100 * ($data['sl_dau_ra_ok'] / $data['sl_dau_ra_kh']));
                    else {
                        $data['ti_le_ht'] = "";
                    }
                } catch (Exception $ex) {
                }
            }
            // sl_ng - spec_vao_hang / sl_dau_vao_hang_loat
            $data['hao_phi'] = $data['sl_dau_vao'] ? round((($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) > 0 ? ($data['sl_tem_ng'] - (int)($hao_phi_vao_hang->value ?? 0)) : 0 / $data['sl_dau_vao']) * 100) . '%' : "";
            $records[] = $data;
        }
        return $records;
    }



    public function infoPallet(Request $request)
    {
        $data = [];
        if ($request->line_id == 9) {
            $data = $this->danhSachPalletBaoOn();
        } else if ($request->line_id == 21) {
            $data = $this->danhSachPalletU();
        } else {
            $data = $this->danhSachPalletIn2Chon($request->line_id);
        }

        return $this->success($data);
    }

    public function lineAssign(Request $request)
    {

        $pallet = Lot::find($request->lot_id);
        // $pallet = $pallet->parrent;
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }

        $line_key = Str::slug($line->name);
        $log = $pallet->log;
        $info = $log->info;

        if (!isset($info[$line_key]['table'])) {
            $info[$line_key]['table'] = [];
        };
        $table =  $info[$line_key]['table'];
        $table[] = $request->users;
        $info[$line_key]['table'] = $table;

        $log->info = $info;

        $log->save();
        return $this->success($log);
    }

    public function listTable()
    {
        $list = LineTable::all();
        $data = [];
        foreach ($list as $item) {
            $data[] = [
                "value" => $item->id,
                "label" => $item->ten_ban
            ];
        }
        return $this->success($data);
    }

    public function lineTableWork(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        // $pallet = $pallet->parrent;
        // $line = Line::find($request->line_id);
        // if (!isset($line)) {
        //     return $this->failure([], "Không tìm thấy công đoạn");
        // }

        // $line_key = Str::slug($line->name);
        $line_key = "chon";
        $log = $pallet->log;
        $info = $log->info;
        $table = $info[$line_key]['table'];
        $sl_ok = 0;

        foreach ($request->table as $key => $t) {
            foreach ($table as $k => $value) {
                if ($value['table_id'] == $t['table_id'] && (!isset($value['so_luong_thuc_te_ok']) || (isset($value['so_luong_thuc_te_ok']) && $value['so_luong_thuc_te_ok'] == ''))) {
                    $user_work = $table[$k];
                    if (isset($t['so_luong_thuc_te_ok']) && !isset($t['so_luong_thuc_te_ok_submited'])) {
                        $sl_ok += $t['so_luong_thuc_te_ok'];
                    }
                    $user_work['so_luong_thuc_te'] = isset($t['so_luong_thuc_te']) ? $t['so_luong_thuc_te'] : '';
                    $user_work['so_luong_thuc_te_submited'] = isset($t['so_luong_thuc_te']) ? Carbon::now() : '';
                    $user_work['so_luong_thuc_te_ok'] = isset($t['so_luong_thuc_te_ok']) ? $t['so_luong_thuc_te_ok'] : '';
                    $user_work['so_luong_thuc_te_ok_submited'] = isset($t['so_luong_thuc_te_ok']) ? Carbon::now() : '';
                    $table[$k] = $user_work;
                }
            }
        }
        $info[$line_key]['table'] = $table;
        $log->info = $info;
        $log->save();
        return $this->success($log);
    }

    public function getTableAssignData(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line_key = "chon";
        $object = new stdClass();
        $object->sl_le_ok = OddBin::where('product_id', $pallet->product_id)->where('lo_sx', $pallet->lo_sx)->sum('so_luong');
        $object->is_result = false;
        $log = $pallet->log;
        $info_cd = InfoCongDoan::where('lot_id', $request->lot_id)->where('line_id', 15)->first();
        $sl_ok = 0;
        if ($log && isset($log['info'][$line_key]) && isset($log['info'][$line_key]['table'])) {
            $info = $log['info'];
            $table = [];
            foreach ($info[$line_key]['table'] as $key => $value) {
                if (!isset($value['so_luong_thuc_te_ok']) || (isset($value['so_luong_thuc_te_ok']) && $value['so_luong_thuc_te_ok'] == '')) {
                    $object->is_result = true;
                    $table[] = $value;
                }
                if (isset($value['so_luong_thuc_te_ok'])) {
                    $sl_ok += (int)$value['so_luong_thuc_te_ok'];
                }
            };
            $object->table = $table;
        } else {
            $object->table = [];
        }
        $object->sl_con_lai = $info_cd->sl_dau_vao_hang_loat - $sl_ok;
        return $this->success($object);
    }


    //QC-

    public function infoQCPallet(Request $request)
    {
        $mark = [
            0,
            0,
            "in",
            "phu",
            "be",
            "boc",
            "gap-dan",
            "chon"
        ];
        $data = [];
        $data = $this->danhSachPalletQC($request->line_id);

        return $this->success($data);
    }

    public function findSpec($test, $spcecs)
    {
        $find = "±";
        // return $test;
        $hang_muc = Str::slug($test->hang_muc);
        foreach ($spcecs as $item) {

            if (str_contains($item->slug, $hang_muc)) {
                if (str_contains($item->value, $find)) {
                    $filtered_value = preg_replace('/-\D+/', '', $item->value);
                    $arr = explode($find, $filtered_value);
                    $test["input"] = true;
                    $test["tieu_chuan"] = filter_var($arr[0], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $test["delta"] =  filter_var($arr[1], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $test['note'] = $item->value;
                    return $test;
                }
            }
        }
        $test['input'] = false;
        return $test;
    }

    public function testList(Request $request)
    {
        $line_test = Line::find($request->line_id);
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $product =  $pallet->product;
        $line_key = Str::slug($line_test->name);
        $list  = TestCriteria::where('line_id', $line_test->id)->where('is_show', 1)->get();
        $reference = array_merge($list->pluck('reference')->toArray(), [$line_test->id]);
        $spcec = Spec::whereIn("line_id", $reference)->whereNotNull('slug')->whereNotNull('name')->where("product_id", $product->id)->whereNotNull('value')->get();
        $data = [];
        $ct = [];
        if ($line_key === 'oqc') {
            foreach ($list as $item) {
                if (!isset($data['dac-tinh'])) {
                    $data['dac-tinh'] = [];
                }
                if ($item->hang_muc == " ") continue;
                if ($this->findSpec($item, $spcec)) array_push($data['dac-tinh'], $this->findSpec($item, $spcec));
                $ct['dac-tinh'] = '';
            }
        } else {
            foreach ($list as $item) {
                if (!isset($data[Str::slug($item->chi_tieu)])) {
                    $data[Str::slug($item->chi_tieu)] = [];
                }
                if ($item->hang_muc == " ") continue;
                if ($this->findSpec($item, $spcec)) array_push($data[Str::slug($item->chi_tieu)], $this->findSpec($item, $spcec));
                $ct[Str::slug($item->chi_tieu)] = $item->chi_tieu;
            }
        }
        return $this->success(
            ["chi_tieu" => $ct, "data" => $data]
        );
    }

    public function getHistoryChecksheet(Request $request)
    {
        $line_test = Line::find($request->line_id);
        $key = $request->key_name;
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $qc_data = [];
        $line_key = Str::slug($line_test->name);
        $log = $pallet->log;
        if (isset($log->info['qc'][$line_key])) {
            if ($line_key === 'gap-dan') {
                if (isset($log->info['qc']['gap-dan']['bat'])) {
                    $bats = $log->info['qc']['gap-dan']['bat'];
                    $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
                    if ($bat && isset($bats[$bat->id])) {
                        $qc_data = $bats[$bat->id];
                    }
                }
            } else {
                $qc_data = $log->info['qc'][$line_key];
            }
        }
        $result = array_intersect_key($qc_data, array_flip(array($key)));
        return $this->success($result);
    }

    public function errorList(Request $request)
    {
        $line = Line::find($request->line_id);
        // if (!isset($line)) return $this->failure([], 'Không tìm thấy công đoạn');

        if ($line) {
            $list = Error::whereHas('line', function ($q) use ($line) {
                return $q->where('line_id', $line->id);
            })->get();
        } else {
            $list = Error::where('noi_dung', '<>', '')->get();
        }

        if ($request->error_id) {
            $order_line = [10, 22, 11, 12, 14, 13, 15, 20]; //In, In lưới, Phủ Bế, Bóc, Gấp dán, Chọn, OQC
            $previous_lines = array_slice($order_line, 0, array_search($line->id, $order_line) + 1);
            // return $previous_lines;
            $erro = Error::where('id', $request->error_id)->whereHas('line', function ($q) use ($previous_lines) {
                return $q->whereIn('line_id', $previous_lines);
            })->first();
            if ($erro) return $this->success($erro);
            return $this->failure([], "Không tìm thấy mã lỗi ở công đoạn này");
        }
        return $this->success($list);
    }

    private function checkSheet($line)
    {
        $machines = $line->machine()->pluck('id');
        $res = CheckSheetLog::whereIn("machine_id", $machines)->whereDate("created_at", Carbon::today())->count();
        if ($res) return true;
        return false;
    }



    public function scanPalletQC(Request $request)
    {

        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $key_line = Str::slug($line->name);
        $log = LSXLog::where("lot_id", $pallet->id)->first();
        if ($key_line === 'iqc' && $pallet->type === 0 && !$log) {
            $log = new LSXLog();
            $log->lot_id = $pallet->id;
            $log->info = [];
            $log->save();
        }
        if (!$log && $line->id != 20) {
            return $this->failure([], 'Chưa vào sản xuất');
        }
        if (!$log) {
            $log = new LSXLog();
            $log['lot_id'] = $pallet->id;
            $log['info'] = [];
            $log->save();
        }
        //Sửa lại key_line là công đoạn trước đó
        $lineList = ['in' => 'kho-bao-on', 'phu' => 'in', 'be' => 'phu', 'gap-dan' => 'be', 'boc' => 'gap-dan', 'chon' => 'boc', 'kho-thanh-pham' => 'chon'];

        $info = $log->info;
        if (!isset($info['qc'])) {
            $info['qc'] = [];
        }
        $tm = $info['qc'];
        if (!isset($tm[$key_line])) {
            $tm[$key_line] = [];
        }
        if (!isset($tm[$key_line]['thoi_gian_vao'])) {
            $tm[$key_line]['thoi_gian_vao'] = Carbon::now();
        }

        if ($key_line === 'oqc') {
            $info_cong_doan = InfoCongDoan::where('line_id', $line->id)->where('lot_id', $request->lot_id)->first();
            if (!$info_cong_doan) {
                $info_cong_doan = new InfoCongDoan();
                $info_cong_doan['type'] = 'sx';
                $info_cong_doan['sl_dau_vao_hang_loat'] = $pallet->so_luong;
                $info_cong_doan['sl_dau_ra_hang_loat'] = $pallet->so_luong;
                $info_cong_doan['lo_sx'] = $pallet->lo_sx;
                $info_cong_doan['lot_id'] = $pallet->id;
                $info_cong_doan['line_id'] = $request->line_id;
                $info_cong_doan['thoi_gian_bat_dau'] = Carbon::now();
                $info_cong_doan['product_id'] = $request->product_id;
                $info_cong_doan->save();
            }
        }

        $info['qc'] = $tm;
        $log->info = $info;
        $log->save();

        return $this->success($log);
    }


    private function formatDataTest($request, $flag = false)
    {

        if (!$flag) {
            $res = [];
            $res[$request->key] = [];
            $res[$request->key]['data'] = $request->data;
            $res[$request->key]['result'] = $request->result;
        } else {
            $res = [];

            $res['data'] = $request->data;
            $res['result'] = $request->result;
        }

        return $res;
    }


    private function formatDataError($request, $errors = [], $flag = false)
    {
        $res = [];
        $res['errors'] = $errors;
        $permission = [];
        foreach ($request->user()->roles as $role) {
            $tm = ($role->permissions()->pluck("slug"));
            foreach ($tm as $t) {
                $permission[] = $t;
            }
        }
        if (!$flag) {
            $res['errors'][] = ['data' => $request->data, 'user_id' => $request->user()->id, 'type' => count(array_intersect(['oqc', 'pqc'], $permission)) > 0 ? 'qc' : 'sx', 'thoi_gian_kiem_tra' => Carbon::now()];
        } else {
            $errors_data = [];
            foreach ($errors as $key => $err) {
                if (!is_numeric($err)) {
                    foreach ($err['data'] as $err_key => $err_val) {
                        if (!isset($errors_data[$err_key])) {
                            $errors_data[$err_key] = 0;
                        }
                        $errors_data[$err_key] += $err_val;
                    }
                } else {
                    if (!isset($errors_data[$key])) {
                        $errors_data[$key] = 0;
                    }
                    $errors_data[$key] += $err;
                }
            }
            $arrays = [json_decode(json_encode($request->data), true), $errors_data];
            // return $arrays;
            foreach ($arrays as $array) {
                foreach ($array ?? [] as $key => $value) {
                    // return [$array, $value];
                    if (!is_numeric($value)) {
                        continue;
                    }
                    if (!isset($merged[$key])) {
                        $merged[$key] = $value;
                    } else {
                        $merged[$key] += $value;
                    }
                }
            }
            // return $merged;
            $res['errors'] = $merged;
        }

        return $res;
    }

    public function resultTest(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $line_key = Str::slug($line->name);
        $log = $pallet->log;
        $info = $log->info;
        if (!isset($info['qc'][$line_key])) {
            $info['qc'][$line_key] = [];
        }
        if ($line_key !== 'gap-dan') {
            $info['qc'][$line_key] = array_merge(
                $info['qc'][$line_key],
                $this->formatDataTest($request),
                ['user_id' => $request->user()->id, 'user_name' => $request->user()->name]
            );
        } else {
            $latest_bats = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
            if ($latest_bats) {
                $info['qc'][$line_key]['bat'][$latest_bats->id] = array_merge($info['qc'][$line_key]['bat'][$latest_bats->id] ?? [], $this->formatDataTest($request));
                $info['qc'][$line_key]['bat'][$latest_bats->id]['user_id']  = $request->user()->id;
                $info['qc'][$line_key]['bat'][$latest_bats->id]['user_name']  = $request->user()->name;
            }
        }
        if ($line_key === 'iqc') {
            $result = array_column(array_intersect_key($info['qc'][$line_key], array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
            if (count($result) === TestCriteria::where('line_id', 23)->get()->groupBy('chi_tieu')->count()) {
                $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
            }
        }
        if ($line_key === 'oqc') {
            $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
        }
        // return $info;
        $log->info =  $info;
        $log->save();
        return $this->success($log);
    }

    public function errorTest(Request $request)
    {
        try {
            DB::beginTransaction();
            if (!isset($request['data'])) {
                return $this->failure([], "Không có dữ liệu");
            }
            $pallet = Lot::find($request->lot_id);
            if (!isset($pallet)) {
                return $this->failure([], "Không tìm thấy pallet");
            }
            $line = Line::find($request->line_id);
            $line_key = Str::slug($line->name);
            if (!isset($line)) {
                return $this->failure([], "Không tìm thấy công đoạn");
            }
            $log = $pallet->log;
            $plan = $pallet->plan;
            $info = $log->info;
            if (!isset($info['qc'][$line_key])) {
                $info['qc'][$line_key] = [];
            }
            $info_cong_doan = $pallet->infoCongDoan()->where('line_id', $line->id)->first();
            $sl_ng = 0;
            $request_errors = 0;
            foreach ($request['data'] as $err_key => $err_val) {
                $request_errors += $err_val;
            }
            if ($line_key !== "gap-dan") {
                $errors = $this->formatDataError($request, isset($info['qc'][$line_key]['errors']) ? $info['qc'][$line_key]['errors'] : [], true);
                $info['qc'][$line_key] = array_merge($info['qc'][$line_key], $this->formatDataError($request, isset($info['qc'][$line_key]['errors']) ? $info['qc'][$line_key]['errors'] : [], false));
                foreach ($errors['errors'] as $value) {
                    $sl_ng += $value;
                }
                $info['qc'][$line_key]['sl_ng'] = $sl_ng;
            } else {
                $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
                $errors = $this->formatDataError($request, isset($info['qc'][$line_key]['bat'][$bat->id]['errors']) ? $info['qc'][$line_key]['bat'][$bat->id]['errors'] : [], true);
                $bat_info = $info['qc'][$line_key]['bat'];
                if (!isset($bat_info[$bat->id])) {
                    $bat_info[$bat->id] = [];
                }
                $bat_info[$bat->id] = array_merge($bat_info[$bat->id], $this->formatDataError($request, isset($info['qc'][$line_key]['bat'][$bat->id]['errors']) ? $info['qc'][$line_key]['bat'][$bat->id]['errors'] : [], false));
                $info['qc'][$line_key]['bat'] = $bat_info;
                foreach ($errors['errors'] ?? [] as $value) {
                    $sl_ng += $value;
                }
                $info['qc'][$line_key]['bat'][$bat->id]['sl_ng'] = $sl_ng;
            }
            if ($line_key === 'oqc') {
                $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
            }
            $log->info = $info;

            if ($info_cong_doan) {
                $sl_con_lai = 0;
                if ($request->line_id == 10 || $request->line_id == 11 || $request->line_id == 12 || $request->line_id == 14 || $request->line_id == 22) {
                    $sl_con_lai = $info_cong_doan->sl_dau_ra_hang_loat - $info_cong_doan->sl_tem_vang - ($sl_ng * $plan->product->so_bat ?? 0);
                    $info_cong_doan['sl_ng'] += $request_errors * $plan->product->so_bat ?? 0;
                } else {
                    if ($line_key === 'gap-dan') {
                        $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
                        $info_cd_bat = $bat->infoCongDoan()->where('line_id', $line->id)->first();
                        $sl_con_lai = $info_cd_bat->sl_dau_ra_hang_loat - $info_cd_bat->sl_tem_vang - $sl_ng;
                    }
                    $info_cong_doan['sl_ng'] += $request_errors;
                }
                if ($sl_con_lai < 0) {
                    return $this->failure([], 'Tổng số lượng tem vàng và NG không được vượt quá số lượng thực tế');
                }
                $info_cong_doan->save();
                $sl_ok = $info_cong_doan->sl_dau_ra_hang_loat - $info_cong_doan->sl_tem_vang - $info_cong_doan->sl_ng;
                if ($sl_ok <= 0 && $info_cong_doan->sl_ng > 0 && in_array($request->line_id, [10, 11, 12, 13, 14, 22])) {
                    $info[$line_key]['thoi_gian_ra'] = Carbon::now();
                    $info[$line_key]['thoi_gian_xuat_kho'] = Carbon::now();
                    $log->info = $info;
                    $log->save();
                    $params = new stdClass();
                    $params->lot_id = $pallet->id;
                    $params->line_id = $line->id;
                    $params->is_pass = true;
                    $this->endIntem($params);
                }
            }
            if ($line_key === 'gap-dan') {
                $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
                $log_bat = $bat->log;
                $info_bat = $log_bat->info;
                $info_bat['qc'][$line_key]['sl_ng'] = $sl_ng;
                $log_bat->info = $info_bat;
                $log_bat->save();

                $info_cd_bat = $bat->infoCongDoan()->where('line_id', $line->id)->first();
                $info_cd_bat['sl_ng'] = $sl_ng;
                $info_cd_bat->save();
            }
            $log->save();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $th;
            return $this->failure($th, "Đã xảy ra lỗi");
        }
        return $this->success($log);
    }


    private function mergeAndSum($d1, $d2)
    {
        // dd($d1,$d2);

        $res = [];
        if (!isset($d1)) $d1 = [];
        if (!isset($d2)) $d2 = [];
        foreach ($d1 as $item) {
            $key = key($item);
            if (isset($res[$key])) {
                $res[$key] += $item[$key];
            } else {
                $res[$key] = $item[$key];
            }
        }
        foreach ($d2 as $item) {
            $key = key($item);
            // dd($item);
            if (isset($res[$key])) {
                $res[$key] += $item[$key];
            } else {
                $res[$key] = $item[$key];
            }
        }
        $ret = [];
        foreach ($res as $key => $item) {
            $ret[] = [
                $key => $item
            ];
        }

        return $ret;
    }


    public function khoanhVung(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        $line_key = Str::slug($line->name);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $log = $pallet->log;
        $info = $log->info;
        if (!isset($info['qc'][$line_key])) {
            $info['qc'][$line_key] = [];
        }
    }


    public function danhSachPalletQC($line_id)
    {
        $line = Line::find($line_id);
        $data = [];
        $line_key = Str::slug($line->name);
        $list  = LSXLog::listPallet($line_key)->get();
        if ($line_key === 'iqc') {
            foreach ($list as $item) {
                if (isset($item->info['qc'][$line_key])) {
                    $data[] = $item->lot->thongTinIQC();
                }
            }
        } else {
            foreach ($list as $item) {
                if (isset($item->lot)) {
                    if (isset($item->info['qc'][$line_key])) {
                        $qc = $item->lot->thongTinQC($line);
                        if ($qc) {
                            $data[] = $item->lot->thongTinQC($line);
                        }
                    }
                }
            }
        }

        return $data;
    }

    public function detailLoSX(Request $request)
    {
        $lot = Lot::find($request->lot_id);
        $plan = $lot->plan;
        // $lo_sx = $plan->loSX;
        $product = $lot->product;
        $lo_sx = $product->lots;
        // return $lo_sx;
        $san_luong = InfoCongDoan::whereIn('lot_id', $lo_sx->pluck('id'))->where('line_id', $request->line_id)->whereDate('created_at', date('Y-m-d'))->get();
        // return $san_luong->toArray();
        // $plan = $lot->plan;
        // $product = $plan->product;
        // $log = $lot->log->info;
        // return $this->success($san_luong);
        if ($request->line_id == 10 ||  $request->line_id == 11 || $request->line_id == 12 || $request->line_id == 14 || $request->line_id == 22) {
            return $this->success([
                "lot_id" => $lot->id,
                "ma_hang" => $product->id,
                "ten_sp" => $product->name,
                "lo_sx" => $lot->lo_sx,
                "sl_ke_hoach" => $plan->sl_thanh_pham ?? 0,
                "sl_thuc_te" => $plan ? $san_luong->sum('sl_dau_ra_hang_loat') / $plan->product->so_bat : 0,
                'sl_tem_vang' => $plan ? $san_luong->sum('sl_tem_vang') / $plan->product->so_bat : 0,
                'sl_ng' => $plan ? $san_luong->sum('sl_ng') / $plan->product->so_bat : 0,
                'sl_dau_ra' => $plan ? $san_luong->sum('sl_dau_ra_hang_loat') / $plan->product->so_bat : 0,
                'ver' => $product->ver,
                'his' => $product->his,
            ]);
        } else {
            return $this->success([
                "lot_id" => $lot->id,
                "ma_hang" => $product->id,
                "ten_sp" => $product->name,
                "lo_sx" => $lot->lo_sx,
                "sl_ke_hoach" => $plan ? $plan->product->so_bat * $plan->sl_thanh_pham : 0,
                "sl_thuc_te" => $san_luong->sum('sl_dau_ra_hang_loat'),
                'sl_tem_vang' => $san_luong->sum('sl_tem_vang'),
                'sl_ng' => $san_luong->sum('sl_ng'),
                'sl_dau_ra' => $san_luong->sum('sl_dau_ra_hang_loat'),
                'ver' => $product->ver,
                'his' => $product->his,
            ]);
        }
    }

    public function qcOverall(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!isset($line)) return $this->failure([], 'Không tìm thấy công đoạn');
        $key_line = Str::slug($line->name);
        $data = new stdClass();
        $query = ProductionPlan::where('cong_doan_sx', $key_line)->whereDate('ngay_sx', date('Y-m-d'));
        $plan = $query->first();
        $info_cong_doan = InfoCongDoan::where('line_id', $line->id)->whereDate('thoi_gian_ket_thuc', Carbon::today())->get();
        if ($request->line_id == 10 || $request->line_id == 11 || $request->line_id == 12 || $request->line_id == 14 || $request->line_id == 22) {
            $data->ke_hoach = (int)$query->sum('sl_thanh_pham');
            $data->muc_tieu = round(($data->ke_hoach / 12) * ((int)date('H') - 6));
            $data->ket_qua = $plan ? $info_cong_doan->sum('sl_dau_ra_hang_loat') / $plan->product->so_bat : 0;
        } else {
            $data->ke_hoach = (int)$query->sum('so_bat') * (int)$query->sum('sl_thanh_pham');
            $data->muc_tieu = round(($data->ke_hoach / 12) * ((int)date('H') - 6));
            $data->ket_qua = $info_cong_doan->sum('sl_dau_ra_hang_loat');
        }
        return $this->success($data);
    }

    public function iqcOverall(Request $request)
    {
        $line = Line::find($request->line_id);
        if (!isset($line)) return $this->failure([], 'Không tìm thấy công đoạn');
        $key_line = Str::slug($line->name);
        $data = new stdClass();
        $lots = Lot::with('log')->where('type', 0)->whereHas('log', function ($query) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime('-4 day')));
        })->get();
        $count = 0;
        foreach ($lots as $lot) {
            $log = $lot->log;
            if (!$log) {
                continue;
            }
            if (isset($log->info['qc']['iqc'])) {
                $result = array_column(array_intersect_key($log->info['qc']['iqc'], array_flip(array('kich-thuoc', 'dac-tinh', 'ngoai-quan'))), 'result');
                if (!in_array(0, $result)) {
                    $count++;
                }
            }
        }
        $data->ke_hoach = count($lots);
        $data->muc_tieu = count($lots);
        $data->ket_qua = $count;
        return $this->success($data);
    }

    public function inTemVang(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $line_key = Str::slug($line->name);
        $nguoi_sx = isset($pallet->log->info[$line_key]['user_name']) ? $pallet->log->info[$line_key]['user_name'] : '';
        $nguoi_qc = isset($pallet->log->info['qc'][$line_key]['user_name']) ? $pallet->log->info['qc'][$line_key]['user_name'] : '';
        if ($line_key === 'gap-dan') {
            $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
            if ($bat) {
                $pallet = $bat;
            }
        }
        $log = $pallet->log;
        if ($line_key === 'gap-dan') {
            $parrent = $pallet->parrent;
            $log_parent = $parrent->log;
            $nguoi_qc = isset($log_parent->info['qc'][$line_key]['bat'][$pallet->id]['user_name']) ? $log_parent->info['qc'][$line_key]['bat'][$pallet->id]['user_name'] : '';
            if (!$log_parent->checkQC($line_key)) return $this->failure([], "Bạn chưa thể in tem, cần kiểm tra lại chất lượng");
        } else {
            if (!$log->checkQC($line_key)) return $this->failure([], "Bạn chưa thể in tem, cần kiểm tra lại chất lượng");
        }

        $info = $log->info;
        $plan = $pallet->plan;
        $product = $pallet->product;
        $san_luong = $pallet->infoCongDoan()->where('line_id', $line->id)->first();
        if ($san_luong->sl_tem_vang <= 0) {
            return $this->failure('', 'Không có số lượng tem vàng không thể in tem');
        }

        $data = [];
        if ($line->id == 20) {
            $cd_tiep_theo = 'Chọn';
        } else if ($line->id == 22) {
            $cd_tiep_theo = 'Bế';
        } else {
            $cd_td = Line::where('ordering', '>', $line->ordering)->orderBy('ordering', 'ASC')->first();
            $cd_tiep_theo = $cd_td->name;
        }
        // return !isset($info['qc'][$line_key]['sl_tem_vang']);
        $new_tem_vang_id = "";
        if ($pallet->type === 3) {
            $parts = explode('.', $pallet->id);
            array_pop($parts);
            $string = implode('.', $parts);
            $new_tem_vang_id = $string . '.TV' . $line->id;
        } else {
            $new_tem_vang_id = $pallet->id . '.TV' . $line->id;
        }

        $check_lot_tv = Lot::where('type', 3)->where('id', 'like', "%" . $new_tem_vang_id . "-_%")->orderBy('created_at', 'DESC')->get();
        $tem_vang = Lot::where('type', 3)->where('id', 'like', "%" . $new_tem_vang_id . "%")->where('p_id', $request->lot_id)->orderBy('created_at', 'DESC')->get();
        $error_list = [];
        if (count($tem_vang) <= 0) {
            $lot_tem_vang = new Lot();
            $lot_tem_vang->id = $new_tem_vang_id . '-' . (count($check_lot_tv) + 1);
            $lot_tem_vang->type = 3;
            $lot_tem_vang->lo_sx = $pallet->lo_sx;
            $lot_tem_vang->so_luong = $san_luong->sl_tem_vang;
            $lot_tem_vang->product_id = $pallet->product_id;
            $lot_tem_vang->p_id = $request->lot_id;
            $lot_tem_vang->save();
            $tem_id = $lot_tem_vang->id;
            $sl_tem_vang = $san_luong->sl_tem_vang;
            if (isset($info['qc'][$line_key]['loi_tem_vang'][0])) {
                if (is_array($info['qc'][$line_key]['loi_tem_vang'][0])) {
                    $error_list = $info['qc'][$line_key]['loi_tem_vang'][0];
                } else {
                    $error_list = [$info['qc'][$line_key]['loi_tem_vang'][0]];
                }
            }
        } else {
            if ($san_luong->sl_tem_vang - $tem_vang->sum('so_luong') > 0) {
                $tem_id = $new_tem_vang_id . '-' . (count($check_lot_tv) + 1);
                $sl_tem_vang = $san_luong->sl_tem_vang - $tem_vang->sum('so_luong');
                Lot::updateOrCreate(['id' => $tem_id], [
                    'id' => $tem_id,
                    'so_luong' => $sl_tem_vang,
                    'type' => 3,
                    'product_id' => $pallet->product_id,
                    'lo_sx' => $pallet->lo_sx,
                    'p_id' => $request->lot_id,
                ]);
                if (isset($info['qc'][$line_key]['loi_tem_vang'][count($tem_vang)])) {
                    if (is_array($info['qc'][$line_key]['loi_tem_vang'][count($tem_vang)])) {
                        $error_list = $info['qc'][$line_key]['loi_tem_vang'][count($tem_vang)];
                    } else {
                        $error_list = [$info['qc'][$line_key]['loi_tem_vang'][count($tem_vang)]];
                    }
                }
            } else {
                $tem_id =  $tem_vang[0]->id ?? "";
                $sl_tem_vang = $tem_vang[0]->so_luong ?? 0;
                if (isset($info['qc'][$line_key]['loi_tem_vang'][count($tem_vang) - 1])) {
                    if (is_array($info['qc'][$line_key]['loi_tem_vang'][count($tem_vang) - 1])) {
                        $error_list = $info['qc'][$line_key]['loi_tem_vang'][count($tem_vang) - 1];
                    } else {
                        $error_list = [$info['qc'][$line_key]['loi_tem_vang'][count($tem_vang) - 1]];
                    }
                }
            }
        }
        if ($line->id == 10 || $line->id == 11 || $line->id == 12 || $line->id == 14 || $line->id == 22) {
            $data = [
                "lot_id" => $tem_id,
                "ma_hang" => $product->id,
                "ten_sp" => $product->name,
                "lo_sx" => $pallet->lo_sx,
                'luong_sx' => ($san_luong && $plan->product->so_bat) ? (($san_luong->sl_dau_ra_hang_loat + $san_luong->sl_dau_ra_chay_thu) / $plan->product->so_bat) : 0,
                'sl_ok' => ($san_luong && $plan->product->so_bat) ? (($san_luong->sl_dau_ra_hang_loat / $plan->product->so_bat) - ($san_luong->sl_tem_vang ? $san_luong->sl_tem_vang / $plan->product->so_bat : 0) - ($san_luong->sl_ng ? $san_luong->sl_ng / $plan->product->so_bat : 0)) : 0,
                'sl_tem_vang' => ($san_luong && $plan->product->so_bat) ? $sl_tem_vang / $plan->product->so_bat : 0,
                'sl_ng' => ($san_luong && $plan->product->so_bat) ? $san_luong->sl_ng / $plan->product->so_bat : 0,
                'sl_dau_ra' => ($san_luong && $plan->product->so_bat) ? $san_luong->sl_dau_ra_hang_loat / $plan->product->so_bat : 0,
                'ver' => $product->ver,
                'his' => $product->his,
                "nguoi_sx" => $nguoi_sx,
                "nguoi_qc" => $nguoi_qc,
                'cd_tiep_theo' => $cd_tiep_theo,
            ];
        } else {
            $data = [
                "lot_id" => $tem_id,
                "ma_hang" => $product->id,
                "ten_sp" => $product->name,
                "lo_sx" => $pallet->lo_sx,
                'luong_sx' => $san_luong ? ($san_luong->sl_dau_ra_hang_loat + $san_luong->sl_dau_ra_chay_thu) : 0,
                'sl_ok' => $san_luong ? ($san_luong->sl_dau_ra_hang_loat - ($san_luong->sl_tem_vang ?? 0) - ($san_luong->sl_ng ?? 0)) : 0,
                'sl_tem_vang' => $sl_tem_vang,
                'sl_ng' => $san_luong ? $san_luong->sl_ng : 0,
                'sl_dau_ra' => $san_luong ? $san_luong->sl_dau_ra_hang_loat : 0,
                'ver' => $product->ver,
                'his' => $product->his,
                "nguoi_sx" => $nguoi_sx,
                "nguoi_qc" => $nguoi_qc,
                'cd_tiep_theo' => $cd_tiep_theo,
            ];
        }
        $errors = Error::whereIn('id', $error_list)->get()->pluck('noi_dung')->toArray();
        $data['ghi_chu'] = implode(', ', $errors);
        if (isset($data['sl_ok']) && $data['sl_ok'] <= 0 && $san_luong->sl_tem_vang > 0) {
            $info[$line_key]['thoi_gian_ra'] = Carbon::now();
            $info[$line_key]['thoi_gian_xuat_kho'] = Carbon::now();
            $log->info = $info;
            $log->save();
            $params = new stdClass();
            $params->lot_id = $pallet->id;
            $params->line_id = $line->id;
            $params->is_pass = true;
            if ($line_key === 'gap-dan') {
                $params->lot_id = $pallet->parrent->id;
                $this->endIntem($params);
            } else {
                $this->endIntem($params);
            }
        }
        $new_id = [$tem_id];
        $new_sl = [$sl_tem_vang];
        $data['new_id'] = $new_id;
        $data['new_sl'] = $new_sl;
        return $this->success($data);
    }

    public function updateSoLuongTemVang(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $line_key = Str::slug($line->name);
        $log = $pallet->log;
        $plan = $pallet->plan;
        $info_cong_doan = InfoCongDoan::where("lot_id", $pallet->id)->where('line_id', $request->line_id)->first();
        if ($info_cong_doan) {
            if ($request->line_id == 10 || $request->line_id == 11 || $request->line_id == 12 || $request->line_id == 14 || $request->line_id == 22) {
                $sl_con_lai = $info_cong_doan->sl_dau_ra_hang_loat - (($request->sl_tem_vang * $plan->product->so_bat ?? 0) + $info_cong_doan->sl_tem_vang) - $info_cong_doan->sl_ng;
                if ($sl_con_lai < 0) {
                    return $this->failure([], 'Tổng số lượng tem vàng và NG không được vượt quá số lượng thực tế');
                }
                $info_cong_doan['sl_tem_vang'] += ($request->sl_tem_vang * $plan->product->so_bat ?? 0);
            } else {
                if ($line_key === 'gap-dan') {
                    $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
                    $info_cd_bat = $bat->infoCongDoan()->where('line_id', $line->id)->first();
                    $sl_con_lai = $info_cd_bat->sl_dau_ra_hang_loat - ($request->sl_tem_vang + $info_cd_bat->sl_tem_vang) - $info_cd_bat->sl_ng;
                    if ($sl_con_lai < 0) {
                        return $this->failure([], 'Tổng số lượng tem vàng và NG không được vượt quá số lượng thực tế');
                    }
                    $bat->so_luong = $sl_con_lai;
                    $bat->save();
                } else {
                    $sl_con_lai = $info_cong_doan->sl_dau_ra_hang_loat - ($request->sl_tem_vang + $info_cong_doan->sl_tem_vang) - $info_cong_doan->sl_ng;
                    if ($sl_con_lai < 0) {
                        return $this->failure([], 'Tổng số lượng tem vàng và NG không được vượt quá số lượng thực tế');
                    }
                }
                if ($line->id == 20) {
                    $info_cong_doan['sl_tem_vang'] = $request->sl_tem_vang;
                } else {
                    $info_cong_doan['sl_tem_vang'] += $request->sl_tem_vang;
                }
            }
            // $info_cong_doan->save();
        }
        $info = $log->info;

        $info['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
        if (!isset($info['qc'][$line_key]['sl_tem_vang'])) {
            $info['qc'][$line_key]['sl_tem_vang'] = 0;
        }
        if ($request->line_id == 10 || $request->line_id == 11 || $request->line_id == 12 || $request->line_id == 14 || $request->line_id == 22) {
            $info['qc'][$line_key]['sl_tem_vang'] += $request->sl_tem_vang * ($plan ? $plan->product->so_bat : 0);
        } else {
            if ($line->id == 20) {
                $info['qc'][$line_key]['sl_tem_vang'] = $request->sl_tem_vang;
            } else {
                $info['qc'][$line_key]['sl_tem_vang'] += $request->sl_tem_vang;
            }
        }
        $errors = Error::whereIn('id', $request->errors)->get()->pluck('id');
        if (!isset($info['qc'][$line_key]['loi_tem_vang'])) {
            $info['qc'][$line_key]['loi_tem_vang'] = [];
            array_push($info['qc'][$line_key]['loi_tem_vang'], $errors->toArray());
        } else {
            $check_lot_tv = Lot::where('type', 3)->where('p_id', $request->lot_id)->orderBy('created_at', 'DESC')->get();
            if (count($check_lot_tv) < count($info['qc'][$line_key]['loi_tem_vang'])) {
                $info['qc'][$line_key]['loi_tem_vang'][count($check_lot_tv)] = array_merge($info['qc'][$line_key]['loi_tem_vang'][count($check_lot_tv)] ?? [], $errors->toArray());
            } else {
                array_push($info['qc'][$line_key]['loi_tem_vang'], $errors->toArray());
            }
        }
        if ($line_key === 'gap-dan') {
            $bat = Lot::where('p_id', $pallet->id)->where('type', 1)->orderBy('created_at', 'DESC')->first();
            if (!isset($info['qc'][$line_key]['bat'])) {
                $info['qc'][$line_key]['bat'] = [];
            }
            if (!isset($info['qc'][$line_key]['bat'][$bat->id])) {
                $info['qc'][$line_key]['bat'][$bat->id] = [];
            }
            if (!isset($info['qc'][$line_key]['bat'][$bat->id]['sl_tem_vang'])) {
                $info['qc'][$line_key]['bat'][$bat->id]['sl_tem_vang'] = 0;
            }
            $info['qc'][$line_key]['bat'][$bat->id]['sl_tem_vang'] += $request->sl_tem_vang;
            $info['qc'][$line_key]['bat'][$bat->id]['loi_tem_vang'] = $errors;
            $log_bat = $bat->log;
            $info_cong_doan_bat = InfoCongDoan::where("lot_id", $bat->id)->where('line_id', $request->line_id)->first();
            if ($info_cong_doan_bat) {
                $info_cong_doan_bat['sl_tem_vang'] += $request->sl_tem_vang;
            }
            $info_bat = $log_bat->info;
            $info_bat['qc'][$line_key]['thoi_gian_ra'] = Carbon::now();
            if (!isset($info_bat['qc'][$line_key]['sl_tem_vang'])) {
                $info_bat['qc'][$line_key]['sl_tem_vang'] = 0;
            }
            if (!isset($info_bat['qc'][$line_key]['loi_tem_vang'])) {
                $info_bat['qc'][$line_key]['loi_tem_vang'] = [];
                array_push($info_bat['qc'][$line_key]['loi_tem_vang'], $errors->toArray());
            } else {
                $check_lot_tv = Lot::where('type', 3)->where('p_id', $request->lot_id)->orderBy('created_at', 'DESC')->get();
                if (count($check_lot_tv) < count($info_bat['qc'][$line_key]['loi_tem_vang']) && is_array($info_bat['qc'][$line_key]['loi_tem_vang'][count($check_lot_tv)])) {
                    $info_bat['qc'][$line_key]['loi_tem_vang'][count($check_lot_tv)] = array_merge($info_bat['qc'][$line_key]['loi_tem_vang'][count($check_lot_tv)] ?? [], $errors->toArray());
                } else {
                    array_push($info_bat['qc'][$line_key]['loi_tem_vang'], $errors->toArray());
                }
            }
            $info_bat['qc'][$line_key]['sl_tem_vang'] += $request->sl_tem_vang;
            $log_bat->info = $info_bat;
            $log_bat->save();
            $info_cong_doan_bat->save();
        }
        $log->info = $info;
        $log->save();
        if ($info_cong_doan) $info_cong_doan->save();
        return $this->success($info_cong_doan);
    }


    // MQTT
    public function power(Request $request)
    {
        $machine_id = 'GL_637CIR';
        Tracking::where('machine_id', $machine_id)->update(['powerM' => $request->powerM]);
        $record = ThongSoMay::where('machine_code', $machine_id)->orderBy('created_at', 'DESC')->first();
        $arr = $record->data_if;
        $arr['powerM'] = number_format($request->powerM, 2);
        $record->update(['data_if' => $arr]);
        $log = MachineParameterLogs::where('machine_id', $machine_id)->where('start_time', '<=', date('Y-m-d H:i:s', $request->timestamp))->where('end_time', '>=', date('Y-m-d H:i:s', $request->timestamp))->first();
        $arr_1 = $log->data_if;
        $arr_1['powerM'] = number_format($request->powerM, 2);
        $log->update(['data_if' => $arr_1]);
        return $this->success(true);
    }
    public function webhook(Request $request)
    {
        $privateKey = "MIGfMA0GCSqGSIb3DQ";
        if ($request->private_key !== $privateKey) {
            return $this->failure([], "Incorrect private_key");
        }
        $machine = Machine::where('code', $request->machine_id)->first();
        if ($request->record_type == 'sx') {
            $status = MachineStatus::getStatus($request->machine_id);
            // if ($status == 0) {
            //     Tracking::updateData($request->machine_id, $request->input, $request->output);
            //     return;
            // }
            $info_cong_doan = InfoCongDoan::where('type', $request->record_type)->where('line_id', $machine->line_id)->where("thoi_gian_bat_dau", '<>', null)->whereNull('thoi_gian_ket_thuc')->orderBy('created_at', 'DESC')->first();

            $sl_bat = $info_cong_doan->lot->product->so_bat;

            $tracking = Tracking::getData($request->machine_id);

            $d_input = $request->input - $tracking->input;
            $d_output = $request->output - $tracking->output;
            if ($machine->line_id != 13) {
                $d_output = $sl_bat * $d_output;
                $d_input = $sl_bat * $d_input;
            }
            if ($d_input < 0) $d_input = 0;
            if ($d_output < 0) $d_output = 0;
            Tracking::updateData($request->machine_id, $request->input, $request->output);
            if ($info_cong_doan) {
                $status = MachineStatus::getStatus($request->machine_id);
                if ($status == 0) { //chạy thử/vào hàng
                    if (!isset($info_cong_doan->sl_dau_vao_chay_thu)) $info_cong_doan->sl_dau_vao_chay_thu = 0;
                    $info_cong_doan->sl_dau_vao_chay_thu += $d_input;

                    if (!isset($info_cong_doan->sl_dau_ra_chay_thu)) $info_cong_doan->sl_dau_ra_chay_thu = 0;
                    $info_cong_doan->sl_dau_ra_chay_thu += $d_output;
                } else if ($status == 1) { // chạy hàng loạt
                    if (!isset($info_cong_doan->sl_dau_vao_hang_loat)) $info_cong_doan->sl_dau_vao_hang_loat = 0;
                    $info_cong_doan->sl_dau_vao_hang_loat += $d_input;

                    if (!isset($info_cong_doan->sl_dau_ra_hang_loat)) $info_cong_doan->sl_dau_ra_hang_loat = 0;
                    $info_cong_doan->sl_dau_ra_hang_loat += $d_output;
                    if ($d_output > 0) {
                        $speed = $request->output - $tracking->output;
                        $machine_speed = MachineSpeed::create(['machine_id' => $request->machine_id, 'speed' => ($speed) * 720]);
                    }
                }
                $info_cong_doan->save();
            }
        }
        if ($request->record_type == 'cl') {
            $log_iot = new MachineIot();
            $log_iot->data = $request->all();
            $log_iot->save();
            if ($request->machine_id == 'bao-on') {
                $insulation = Insulation::find(1);
                if ($insulation) {
                    $insulation->update(['t_ev' => $request->t_ev, 'e_hum' => $request->e_hum]);
                } else {
                    Insulation::create(['t_ev' => $request->t_ev, 'e_hum' => $request->e_hum]);
                }
            }
            $tracking = Tracking::where('machine_id', $request->machine_id)->first();
            LogWarningParameter::checkParameter($request);
            if (!$tracking) {
                $tracking = new Tracking();
                $tracking->machine_id = $request->machine_id;
                $tracking->timestamp = $request->timestamp;
                $tracking->save();
            }
            if (is_null($tracking->timestamp)) {
                $tracking->update(['timestamp' => $request->timestamp]);
            }
            if (!is_null($tracking->timestamp)) {
                if ($request->timestamp  >= ($tracking->timestamp +  300)) {
                    $start = $tracking->timestamp;
                    $end = $tracking->timestamp +  300;
                    $logs = MachineIot::where('data->record_type', "cl")->where('data->machine_id', $request->machine_id)->where('data->timestamp', '>=', $start)->where('data->timestamp', '<=', $end)->pluck('data')->toArray();
                    $parameters = MachineParameters::where('machine_id', $request->machine_id)->where('is_if', 1)->pluck('parameter_id')->toArray();
                    $arr = [];
                    foreach ($parameters as $key => $parameter) {
                        $arr[$parameter] = 0;
                        foreach ((array) $logs as $key => $log) {
                            if (isset($log[$parameter])) {
                                if (in_array($parameter, ['uv1', 'uv2', 'uv3'])) {
                                    $arr[$parameter] = $log[$parameter];
                                } else {
                                    $arr[$parameter] = (float)$arr[$parameter] + (float)$log[$parameter];
                                }
                            }
                        }
                    }
                    foreach ($parameters as $key => $parameter) {
                        if (!in_array($parameter, ['uv1', 'uv2', 'uv3'])) {
                            $arr[$parameter] = $logs ? number_format($arr[$parameter] / count($logs), 2) : 0;
                        }
                    }
                    $machine_speed = MachineSpeed::where('machine_id', $machine->code)->get();
                    if (count($machine_speed)) {
                        $arr['speed'] = number_format($machine_speed->sum('speed') / $machine_speed->count());
                        MachineSpeed::where('machine_id', $machine->code)->delete();
                    }
                    MachineIot::where('data->record_type', "cl")->where('data->machine_id', $request->machine_id)->delete();
                    Tracking::where('machine_id', $request->machine_id)->update(['timestamp' => $request->timestamp]);
                    MachineParameterLogs::where('machine_id', $request->machine_id)->where('start_time', '<=', date('Y-m-d H:i:s', $request->timestamp))->where('end_time', '>=', date('Y-m-d H:i:s', $request->timestamp))->update(['data_if' => $arr]);
                    if ($machine) {
                        $line = $machine->line;
                        $updated_tracking = Tracking::where('machine_id', $machine->code)->first();
                        $lot = Lot::find($updated_tracking->lot_id);
                        $thong_so_may = new ThongSoMay();
                        $ca = (int)date('H', $request->timestamp);
                        $thong_so_may['ngay_sx'] = date('Y-m-d H:i:s');
                        $thong_so_may['ca_sx'] = ($ca >= 7 && $ca <= 17) ? 1 : 2;
                        $thong_so_may['xuong'] = '';
                        $thong_so_may['line_id'] = $line->id;
                        $thong_so_may['lot_id'] = $lot ? $lot->id : null;
                        $thong_so_may['lo_sx'] = $lot ? $lot->lo_sx : null;
                        $thong_so_may['machine_code'] = $machine->code;
                        $thong_so_may['data_if'] = $arr;
                        $thong_so_may['date_if'] = date('Y-m-d H:i:s', $request->timestamp);
                        $thong_so_may->save();
                    }
                }
            }
        }
        ##
        if ($request->record_type == "tb") {
            $tracking = Tracking::where('machine_id', $request->machine_id)->first();
            $tracking->update(['status' => $request->status]);
            if ($tracking->lot_id) {
                $res = MachineLog::UpdateStatus($request);
            }
        }
        ##
        // if(isset($tracking) && !is_null($tracking->lot_id)){
        $iot_log = new IOTLog();
        $input = $request->all();
        $iot_log->data = $input;
        $iot_log->save();
        // }
        return $this->success([]);
    }

    public function frequency(Request $request)
    {
        $privateKey = "MIGfMA0GCSqGSIb3DQ";
        if ($request->private_key !== $privateKey) {
            return $this->failure([], "Incorrect private_key");
        }
        return '1000@500@300';
    }
    public function webhook_history()
    {
        $history = IOTLog::all();
        return $this->success($history);
    }

    public function recallIOT(Request $request)
    {
        $privateKey = "MIGfMA0GCSqGSIb3DQ";
        if ($request->private_key !== $privateKey) {
            return $this->failure([], "Incorrect private_key");
        }
        return [
            "record_type" => "sx",
            "time_start" => "1690859776",
            "time_end" => "1690859776",
            "machine_id" => "SN_UV"
        ];
    }

    public function tinhSanLuongIOT(Request $request)
    {
        $privateKey = "MIGfMA0GCSqGSIb3DQ";
        if ($request->private_key !== $privateKey) {
            return $this->failure([], "Incorrect private_key");
        }
        $machine = Machine::where('code', $request->machine_id)->first();
        $info_cong_doan = InfoCongDoan::where('line_id', $machine->line_id)->whereNotNull("thoi_gian_bat_dau")->whereNull('thoi_gian_ket_thuc')->orderBy('created_at', 'DESC')->first();
        if ($info_cong_doan) {
            $info_cong_doan['thoi_gian_bam_may'] = date('Y-m-d H:i:s', $request->timestamp);
            $info_cong_doan->save();
        }
        MachineStatus::active($request->machine_id);
        return $info_cong_doan;
        // return [
        //     "record_type" => "tsl",
        //     "machind_id" => "SN_UV",
        //     "timestamp" => "1690859776"
        // ];
    }

    public function thuNghiemIOT(Request $request)
    {
        $privateKey = "MIGfMA0GCSqGSIb3DQ";
        if ($request->private_key !== $privateKey) {
            return $this->failure([], "Incorrect private_key");
        }
        return [

            "machind_id" => "SN_UV",
            "timetamp" => "1690859776"
        ];
    }

    //END MQTT
    public function listLot(Request $request)
    {
        $input = $request->all();
        $query = MaterialExportLog::orderBy('material_id', 'DESC')->orderBy('created_at', 'DESC');
        if (isset($input['start_date'])) {
            $query->whereDate('created_at', '>=', $input['start_date']);
        } else {
            $query->whereDate('created_at', date('Y-m-d'));
        }
        if (isset($input['end_date'])) {
            $query->whereDate('created_at', '<=', $input['end_date']);
        } else {
            $query->whereDate('created_at', date('Y-m-d'));
        }
        $records = $query->get();
        $data = [];
        foreach ($records as $key => $record) {
            $lots = Lot::with('plan')->where('type', 0)->where('material_export_log_id', $record->id)->orderBy('created_at', 'DESC')->get();
            if (count($lots) > 0) {
                foreach ($lots as $k => $lot) {
                    if (!$lot->plan) continue;
                    $object = new \stdClass();
                    $product = Product::where('id', $lot->product_id)->first();
                    $object->id = $record->id;
                    $object->lsx = $lot->lo_sx;
                    $object->sl_kho_xuat = $record->sl_kho_xuat;
                    $object->sl_thuc_te = $record->sl_thuc_te;
                    $object->so_luong_thieu = $record->sl_kho_xuat - $record->sl_thuc_te;
                    $object->lot_id = $lot->id;
                    $object->ngay_sx = date("d/m/Y", strtotime($lot->plan->ngay_sx));
                    $object->tg_sx = $lot->plan->thoi_gian_bat_dau;
                    $object->product_id =  $product->id;
                    $object->ten_sp = $product->name;
                    $object->quy_cach = $product->kt_kho_dai . '*' . $product->kt_kho_rong;
                    $object->khach_hang = $lot->plan->khach_hang;
                    $object->manvl =  $product->material_id;
                    $object->soluongtp = $lot->so_luong;
                    $object->so_luong_ke_hoach = $lot->plan->sl_nvl;
                    $object->status = $record->status;
                    $object->sl_kho_xuat = $record->sl_kho_xuat;
                    $object->cd_tiep_theo = 'Bảo ôn';
                    $data[] = $object;
                }
            } else {
                $object = new \stdClass();
                $object->id = $record->id;
                $object->lsx = '';
                $object->sl_kho_xuat = $record->sl_kho_xuat;
                $object->sl_thuc_te = $record->sl_thuc_te;
                $object->so_luong_thieu = $record->sl_kho_xuat - $record->sl_thuc_te;
                $object->lot_id = '';
                $object->ma_sp = '';
                $object->ten_sp = '';
                $object->quy_cach = '';
                $object->khach_hang = '';
                $object->manvl = $record->material_id;
                $object->soluongtp = '';
                $object->status = $record->status;
                $object->cd_tiep_theo = 'Bảo ôn';
                $data[] = $object;
            }
        }
        return $this->success($data);
    }

    //Upload KHSX

    public function uploadKHSX()
    {
        // $hash = hash_file("md5", $_FILES['files']['tmp_name']);
        // $lists = ProductionPlan::where("file", $hash);
        // $lists->delete();
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
            //Lấy dứ liệu từ dòng thứ 5
            if ($key > 3 && !is_null($row['H']) && !is_null($row['I'])) {
                if (is_null($row['B'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thứ tự ưu tiên');
                }
                if (is_null($row['C'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thời gian bắt đầu');
                }
                if (is_null($row['D'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thời gian kết thúc');
                }
                if (is_null($row['E'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thời gian ngày sản xuất');
                }
                if (is_null($row['G'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu công đoạn sản xuất');
                }
                if (is_null($row['H'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu máy sản xuất');
                }
                if (is_null($row['I'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu mã sản phẩm');
                }
                if (is_null($row['L'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu lô sản xuất');
                }
                if (is_null($row['M'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu ngày giao hàng');
                }
                if (is_null($row['AD'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu ngày đặt hàng');
                }
                if (is_null($row['H'])) {
                    break;
                }
                $line = Line::query()->where('factory_id', 2)->where('name', 'like', trim($row['G']))->first();
                if (empty($line)) throw new Exception('Không tìm thấy công đoạn');

                if (!is_null($row['B'])) {
                    $input['product_order_id'] = $row['L'];
                    $input['ngay_dat_hang'] = date('Y-m-d', strtotime(str_replace('/', '-', $row['AD'])));
                    $input['cong_doan_sx'] = Str::slug($row['G']); //
                    $input['line_id'] = $line->id; //
                    $input['ca_sx'] = $row['F']; //
                    $input['ngay_sx'] = date('Y-m-d', strtotime(str_replace('/', '-', $row['E'])));
                    $input['ngay_giao_hang'] = date('Y-m-d', strtotime(str_replace('/', '-', $row['M'])));
                    $input['machine_id'] = $row['H']; //
                    $input['product_id'] = $row['I']; //
                    $input['product_name'] = $row['K'];
                    $input['khach_hang'] = $row['J']; //
                    $input['so_bat'] = $row['T'] ?? 0; //
                    $input['sl_nvl'] = $row['O']; //
                    $input['sl_tong_don_hang'] = $row['N']; //
                    $input['sl_giao_sx'] = $row['Q']; //
                    $input['sl_thanh_pham'] = $row['P'] ?? 0; //
                    $input['thu_tu_uu_tien'] = $row['B']; //
                    $input['note'] = $row['AE'] ?? "";
                    $input['UPH'] = str_replace(',', '', $row['W']); //
                    $input['nhan_luc'] = $row['AB'];
                    $input['tong_tg_thuc_hien'] = filter_var($row['AA'], FILTER_SANITIZE_NUMBER_INT); //
                    $input['kho_giay'] =  $row['U'] ?? "";
                    $input['toc_do'] =  $row['V'] ? (int)$row['V'] : "";
                    $input['thoi_gian_chinh_may'] =  $row['X'] ? (float)$row['X'] : "";
                    $input['thoi_gian_thuc_hien'] =  $row['Y'] ? (float)$row['Y'] : "";
                    $input['thoi_gian_bat_dau'] = date('Y-m-d H:i:s', strtotime($input['ngay_sx'] . ' ' . $row['C']));
                    $input['thoi_gian_ket_thuc'] = date('Y-m-d H:i:s', strtotime($input['ngay_sx'] . ' ' . $row['D'] . (strtotime($row['C']) > strtotime($row['D']) ? " +1 day" : "")));
                    $input['status'] = InfoCongDoan::STATUS_PLANNED;
                    $data[] = $input;
                    unset($input);
                }
            }
        }
        DB::beginTransaction();
        try {
            foreach ($data as $key => $input) {
                $losx = Losx::firstOrCreate(['product_order_id' => $input['product_order_id']]);
                $input['lo_sx'] = $losx->id;
                // if ($input['line_id'] == 24) {
                //     $this->createPlanForLineLienHoan($input);
                // } else {
                $this->createPlanForOtherLines($input);
                // }
            }
            DB::commit();
            return $this->success([], 'Upload thành công');
        } catch (\Exception $ex) {
            Log::error($ex);
            DB::rollBack();
            return $this->failure([], $ex->getMessage(), 500);
        }
    }

    public function uploadInfoHistory()
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
        // return $allDataInSheet;
        $data = [];
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 5
            if ($key > 2 && !is_null($row['A'])) {
                if (is_null($row['A'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thứ tự ưu tiên');
                }
                if (is_null($row['B'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thời gian bắt đầu');
                }
                if (is_null($row['C'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thời gian kết thúc');
                }
                if (is_null($row['D'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu thời gian ngày sản xuất');
                }
                if (is_null($row['E'])) {
                    return $this->failure([], 'Dòng số ' . $key . ': Thiếu công đoạn sản xuất');
                }

                $machine = Machine::query()->where('code', $row['D'])->first();
                if (empty($machine)) continue;
                $product = Product::where('id', 'like', $row['C'])->first();
                if (empty($product)) continue;

                if (!is_null($row['B'])) {
                    $input['lo_sx'] = $row['B'];
                    if (str_contains($row['B'], '/')) {
                        $input['lo_sx'] = '24' . str_pad(explode('/', $row['B'])[1], 2, '0', STR_PAD_LEFT) . str_pad(explode('/', $row['B'])[0], 2, '0', STR_PAD_LEFT);
                    }
                    $input['ngay_dat_hang'] = date('Y-m-d', strtotime(str_replace('/', '-', $row['A'])));
                    $input['cong_doan_sx'] = Str::slug($machine->line->name); //
                    $input['line_id'] = $machine->line_id; //
                    $input['ca_sx'] = 1; //
                    $input['ngay_sx'] = date('Y-m-d', strtotime(str_replace('/', '-', $row['A'])));
                    $input['ngay_giao_hang'] = date('Y-m-d', strtotime(str_replace('/', '-', $row['A'])));
                    $input['machine_id'] = $machine->code; //
                    $input['product_id'] = $product->id; //
                    $input['product_name'] = $product->name;
                    $input['khach_hang'] = 'Sam Sung'; //
                    $input['so_bat'] = 2; //
                    $input['sl_nvl'] = 0; //
                    $input['sl_tong_don_hang'] = $row['E']; //
                    $input['sl_giao_sx'] = $row['E']; //
                    $input['sl_thanh_pham'] = $row['E'] ?? 0; //
                    $input['thu_tu_uu_tien'] = 1; //
                    $input['note'] = "";
                    $input['UPH'] = 0; //
                    $input['nhan_luc'] = 1;
                    $input['tong_tg_thuc_hien'] = 10; //
                    $input['kho_giay'] =  "";
                    $input['toc_do'] =  "";
                    $input['thoi_gian_chinh_may'] = 10;
                    $input['thoi_gian_thuc_hien'] = 10;
                    $input['thoi_gian_bat_dau'] = date('Y-m-d 07:00:00', strtotime($input['ngay_sx']));
                    $input['thoi_gian_ket_thuc'] = date('Y-m-d 19:00:00', strtotime($input['ngay_sx']));
                    $input['status'] = InfoCongDoan::STATUS_COMPLETED;
                    $data[] = $input;
                    unset($input);
                }
            }
        }
        // return $data;
        DB::beginTransaction();
        try {
            foreach ($data as $key => $input) {
                $this->createInfoHistory($input);
            }
            DB::commit();
            return $this->success([], 'Upload thành công');
        } catch (\Exception $ex) {
            Log::error($ex);
            DB::rollBack();
            return $this->failure([], $ex->getMessage(), 500);
        }
    }

    public function createInfoHistory($input)
    {
        $customer = Customer::firstOrCreate(
            ['name' => $input['khach_hang']],
            ['name' => $input['khach_hang'], 'id' => Str::slug($input['khach_hang'])]
        );
        // Product order
        // $productOrder = ProductOrder::find($input['product_order_id']);
        // if (empty($productOrder)) {
        //     $productOrder = ProductOrder::create([
        //         'id' => $input['product_order_id'],
        //         'order_number' => $input['product_order_id'],
        //         'customer_id' => $customer->id,
        //         'product_id' => $input['product_id'],
        //         'order_date' => $input['ngay_dat_hang'],
        //         'quantity' => $input['sl_thanh_pham'],
        //         'delivery_date' => $input['ngay_giao_hang'],
        //     ]);
        // }
        $record = ProductionPlan::query()->where([
            ['machine_id', $input['machine_id']],
            ['lo_sx', $input['lo_sx']],
            ['product_id', $input['product_id']],
        ])->first();
        if (isset($record)) return null;
        $input['material_id'] = null;
        $record = ProductionPlan::create($input);

        $spec = Spec::query()->where('product_id', $input['product_id'])->where('line_id', '24')->where('slug', 'so-luong')->first();
        $lotsize = 1;
        if ($spec) {
            if (!isset($spec->value)) throw new Exception('Không tìm thấy giá trị của Spec');
            $lotsize = $spec->value;
        } else {
            // throw new Exception("Không tìm thấy định mức cuộn ".$input['product_id']);
            $lotsize = 11000;
        }
        $numbers = $this->getQuantityArray(intval(str_replace(",", "", $input['sl_giao_sx'])), $lotsize);
        $countLot = InfoCongDoan::query()->where([
            ['lo_sx', $input['lo_sx']],
            ['line_id', $input['line_id']]
        ])->count();
        foreach ($numbers as $number) {
            $countLot++;
            $info_cong_doan = [
                'lot_id' => $input['lo_sx'] . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT),
                // 'lotsize' => $number, // 👈 Định mức cuộn
                'lo_sx' => $input['lo_sx'],
                'line_id' => $input['line_id'],
                'product_id' => $input['product_id'],
                'status' => InfoCongDoan::STATUS_COMPLETED,
                'machine_code' => $input['machine_id'],
                'sl_kh' => $number, // 
                'thoi_gian_bat_dau' => $input['thoi_gian_bat_dau'],
                'thoi_gian_ket_thuc' => $input['thoi_gian_ket_thuc'],
                'thoi_gian_bam_may' => $input['thoi_gian_bat_dau'],
                'sl_dau_vao_hang_loat' => $number,
                'sl_dau_ra_hang_loat' => $number,
                'created_at' => $input['thoi_gian_bat_dau'],
                'updated_at' => $input['thoi_gian_ket_thuc'],
            ];
            $lotPlan = LotPlan::firstOrCreate(
                [
                    'lot_id' => $input['lo_sx'] . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT),
                    'line_id' => $input['line_id'],
                    'machine_code' => $input['machine_id'],
                    'product_id' => $input['product_id'],
                    'lo_sx' => $input['lo_sx'],
                ],
                [
                    'start_time' => $input['thoi_gian_bat_dau'],
                    'end_time' => $input['thoi_gian_ket_thuc'],
                    'quantity' => $number,
                    'product_order_id' => $input['lo_sx'],
                    'customer_id' => $customer->id,
                    'production_plan_id' => $record->id,
                    'lot_size' => $number,
                ]
            );
            $info_cong_doan['lot_plan_id'] = $lotPlan->id;
            $record = InfoCongDoan::create($info_cong_doan);
            QCHistory::create([
                'user_id' => 1,
                'info_cong_doan_id' => $record->id,
                'scanned_time' => $input['thoi_gian_ket_thuc'],
                'eligible_to_end' => 1,
            ]);
            Lot::updateOrCreate(
                [
                    'id' => $input['lo_sx'] . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT)
                ],
                [
                    'lo_sx' => $input['lo_sx'],
                    'type' => 0,
                    'material_export_log_id' => null,
                    'product_id' => $input['product_id'],
                    'line_id' => $input['line_id'],
                    'status' => 1,
                    'so_luong' => $number,
                    'plan_id' => $record->id,
                ]
            );
        }
    }



    public function createPlanForLineLienHoan($input)
    {
        $customer = Customer::firstOrCreate(
            ['name' => $input['khach_hang']],
            ['id' => Str::slug($input['khach_hang'])]
        );
        // Product order
        $productOrder = ProductOrder::find($input['product_order_id']);
        if (empty($productOrder)) {
            $productOrder = ProductOrder::create([
                'id' => $input['product_order_id'],
                'order_number' => $input['product_order_id'],
                'customer_id' => $customer->id,
                'material_id' => $input['material_id'],
                'order_date' => $input['ngay_dat_hang'],
                'quantity' => $input['sl_thanh_pham'],
                'delivery_date' => $input['ngay_giao_hang'],
            ]);
        }
        $record = ProductionPlan::query()->where([
            ['machine_id', $input['machine_id']],
            ['lo_sx', $input['lo_sx']],
            ['material_id', $input['material_id']],

        ])->first();
        if (isset($record)) throw new Exception("Kế hoạch cho LoSX:{$record->lo_sx} - {$record->product_id} đã được tạo");
        $input['product_id'] = null;
        $record = ProductionPlan::create($input);
        // TODO: add field lotsize to info_cong_doan table (lot)
        // ID Lot: Mã lô+.L.0001
        $lotsize = 11000;
        $numbers = $this->getQuantityArray(intval(str_replace(",", "", $input['sl_giao_sx'])), $lotsize);
        $countLot = InfoCongDoan::query()->where([
            ['lo_sx', $input['lo_sx']],
            ['line_id', $input['line_id']]
        ])->count();
        foreach ($numbers as $number) {
            $countLot++;
            $info_cong_doan = [
                'lot_id' => $input['lo_sx'] . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT),
                // 'lotsize' => $number, // 👈 Định mức cuộn
                'lo_sx' => $input['lo_sx'],
                'line_id' => $input['line_id'],
                'material_id' => $input['material_id'],
                'status' => $input['status'],
                'machine_code' => $input['machine_id'],
                'sl_kh' => $number, // 
            ];
            InfoCongDoan::create($info_cong_doan);
        }
    }

    public function createPlanForOtherLines($input)
    {
        $customer = Customer::firstOrCreate(
            ['name' => $input['khach_hang']],
            ['name' => $input['khach_hang'], 'id' => Str::slug($input['khach_hang'])]
        );
        // Product order
        $productOrder = ProductOrder::find($input['product_order_id']);
        if (empty($productOrder)) {
            $productOrder = ProductOrder::create([
                'id' => $input['product_order_id'],
                'order_number' => $input['product_order_id'],
                'customer_id' => $customer->id,
                'product_id' => $input['product_id'],
                'order_date' => $input['ngay_dat_hang'],
                'quantity' => $input['sl_thanh_pham'],
                'delivery_date' => $input['ngay_giao_hang'],
            ]);
        }
        $record = ProductionPlan::query()->where([
            ['machine_id', $input['machine_id']],
            ['lo_sx', $input['lo_sx']],
            ['product_id', $input['product_id']],
        ])->first();
        if (isset($record)) throw new Exception("Kế hoạch cho LoSX:{$record->lo_sx} - {$record->product_id} đã được tạo");
        $input['material_id'] = null;
        $input['status_plan'] = ProductionPlan::STATUS_PENDING;
        $record = ProductionPlan::create($input);
        // TODO: add field lotsize to info_cong_doan table (lot)
        // ID Lot: Mã lô+.L.0001
        $spec = Spec::query()->where('product_id', $input['product_id'])->where('line_id', '24')->where('slug', 'so-luong')->first();
        $lotsize = 1;
        // if ($input['line_id'] === 24) {
        //     $info_cong_doan['product_id'] = null;
        //     $info_cong_doan['material_id'] = $input['product_id'];
        // }
        if ($spec) {
            if (!isset($spec->value)) throw new Exception('Không tìm thấy giá trị của Spec');
            $lotsize = $spec->value;
        } else {
            throw new Exception("Không tìm thấy định mức cuộn");
        }
        $numbers = $this->getQuantityArray(intval(str_replace(",", "", $input['sl_giao_sx'])), $lotsize);
        $countLot = InfoCongDoan::query()->where([
            ['lo_sx', $input['lo_sx']],
            ['line_id', $input['line_id']]
        ])->count();
        foreach ($numbers as $number) {
            $countLot++;
            $info_cong_doan = [
                'lot_id' => $input['lo_sx'] . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT),
                // 'lotsize' => $number, // 👈 Định mức cuộn
                'lo_sx' => $input['lo_sx'],
                'line_id' => $input['line_id'],
                'product_id' => $input['product_id'],
                'status' => $input['status'],
                'machine_code' => $input['machine_id'],
                'sl_kh' => $number, // 
            ];
            LotPlan::firstOrCreate(
                [
                    'lot_id' => $input['lo_sx'] . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT),
                    'line_id' => $input['line_id'],
                    'machine_code' => $input['machine_id'],
                    'product_id' => $input['product_id'],
                    'lo_sx' => $input['lo_sx'],
                ],
                [
                    'start_time' => $input['thoi_gian_bat_dau'],
                    'end_time' => $input['thoi_gian_ket_thuc'],
                    'quantity' => $number,
                    'product_order_id' => $input['product_order_id'],
                    'customer_id' => $customer->id,
                    'production_plan_id' => $record->id,
                    'lot_size' => $number,
                ]
            );
        }
    }

    function getQuantityArray($quantity, $default)
    {
        // Calculate the full parts and the remainder
        $fullParts = intdiv($quantity, $default);
        $remainder = $quantity % $default;
        // Create an array filled with full parts
        $result = array_fill(0, $fullParts, $default);
        // Add the remainder if it is not zero
        if ($remainder !== 0) {
            $result[] = $remainder;
        }
        return $result;
    }

    public function uploadKHXK(Request $request)
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
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 5
            if ($key > 3 && !is_null($row['D'])) {
                if (is_null($row['D']) || is_null($row['E'])) {
                    break;
                }
            }
        }
        $khach_hang = '';
        $ngay_xuat_hang = '';
        $cua_xuat_hang = '';
        $dia_chi = '';
        foreach ($allDataInSheet as $key => $row) {
            //Lấy dứ liệu từ dòng thứ 5
            if ($key > 3 && !is_null($row['D'])) {
                if (is_null($row['D']) || is_null($row['E'])) {
                    break;
                }
                if (!is_null($row['B'])) {
                    $khach_hang = $row['B'];
                    $ngay_xuat_hang = $row['C'];
                    $cua_xuat_hang = $row['P'];
                    $dia_chi = $row['Q'];
                }
                $input['khach_hang'] = $khach_hang;
                $input['ngay_xuat_hang'] = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $ngay_xuat_hang)));
                $input['product_id'] = $row['D'];
                $input['ten_san_pham'] = $row['E'];
                $input['po_pending'] = str_replace(',', '', $row['F']);
                $input['sl_yeu_cau_giao'] = str_replace(',', '', $row['G']);
                $input['dvt'] = $row['H'];
                $input['tong_kg'] = $row['I'];
                $input['quy_cach'] = $row['J'];
                $input['sl_thung_chan'] = !is_null($row['K']) ? $row['K'] : 0;
                $input['sl_hang_le'] = $row['L'];
                $product_import = WareHouseLog::where('lot_id', 'like', "%" . $input['product_id'] . "%")->where('type', 1)->sum('so_luong');
                $product_export = WareHouseLog::where('lot_id', 'like', "%" . $input['product_id'] . "%")->where('type', 2)->sum('so_luong');
                $sl_ton = $product_import - $product_export;
                $input['ton_kho'] = $sl_ton ?? 0;
                $input['xac_nhan_sx'] = $row['N'];
                $input['sl_chenh_lech'] = str_replace(',', '', $row['O']);
                $input['cua_xuat_hang'] = $cua_xuat_hang;
                $input['dia_chi'] = $dia_chi;
                $input['ghi_chu'] = $row['R'];
                $record = WareHouseExportPlan::create($input);
                unset($input);
            }
        }
        return $this->success([], '');
    }
    public function getListWareHouseExportPlan(Request $request)
    {
        $list_query = WareHouseExportPlan::with('product')->select('*');
        if ($request->date && count($request->date) > 1) {
            $list_query->whereDate('ngay_xuat_hang', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('ngay_xuat_hang', '<=', date('Y-m-d', strtotime($request->date[1])));
        }
        if ($request->khach_hang) {
            $list_query->where('khach_hang', 'like', $request->khach_hang);
        }
        if ($request->ten_sp) {
            $list_query->where('product_id', $request->ten_sp);
        }
        $data = $list_query->get();
        foreach ($data as $key => $value) {
            $value->customer_id = $value->product->customer_id;
            $value->khach_hang = $value->product->customer->name;
        }
        return $this->success($data);
    }

    function find_line_by_slug($needle, $haystack)
    {
        foreach ($haystack as $item) {
            if (Str::slug($item->name) === $needle) {
                return $item->name;
                break;
            }
        }
    }
    public function getProposeImport(Request $request)
    {
        $input = $request->all();
        $lot = Lot::find($input['lot_id']);
        if (!$lot) {
            return $this->failure([], "Mã thùng không tồn tại");
        }
        $check_lot = DB::table('cell_lot')->where('lot_id', $input['lot_id'])->count();
        if ($check_lot) {
            return $this->failure([], "Mã thùng đã có trong kho");
        }
        $lot_parrent = Lot::find($lot->p_id);
        if (!$lot_parrent || ($lot_parrent && $lot_parrent->type != 2)) {
            $log = $lot->log;
            if ($log) {
                $info = $log->info;
                if (isset($info['qc']) && isset($info['qc']['oqc'])) {
                    if (isset($info['qc']['oqc']['sl_ng']) && (int)$info['qc']['oqc']['sl_ng'] > 0) {
                        return $this->failure([], 'Có hàng NG');
                    }
                } else {
                    return $this->failure([], 'Chưa qua OQC');
                }
            } else {
                return $this->failure([], 'Chưa qua OQC');
            }
        }
        $data = new \stdClass();
        $data->so_luong = $lot->so_luong;
        $data->khach_hang = $lot->product->customer_id;
        $data->ten_san_pham = $lot->product->name;
        $data->ma_thung = $input['lot_id'];
        $product = Product::find($lot->product_id);
        $cell_check = Cell::where('product_id', $product->id)->count();
        $number_of_bin = 5;
        if ($product->chieu_rong_thung >= 340) {
            $number_of_bin = 4;
        }
        if ($cell_check === 0) {
            $cell = Cell::where('number_of_bin', 0)->whereNull('product_id')->orderBy('name', 'ASC')->first();
            if (!$cell) {
                $cell = Cell::where('number_of_bin', 0)->orderBy('name', 'ASC')->first();
            }
            if (!$cell) {
                return $this->failure('', 'Không còn vị trí phù hợp');
            }
            $data->vi_tri_de_xuat = $cell->id;
        } else {
            $cell_find = Cell::where('product_id', $product->id)->where('number_of_bin', '<', $number_of_bin)->orderBy('id', 'ASC')->first();
            if ($cell_find) {
                $data->vi_tri_de_xuat = $cell_find->id;
            } else {
                // $cell_propose = Cell::where('product_id', $product->id)->orderBy('name', 'DESC')->first();
                // $row = explode('.', $cell_propose->id)[0]; //Tầng
                // $col = explode('.', $cell_propose->id)[1]; // Ô
                // if ((int)($col) < 8) {
                //     $data->vi_tri_de_xuat = $row . '.' . sprintf("%02d", (int)($col) + 1);
                // } else {
                //     if ($row < 2) {
                //         $data->vi_tri_de_xuat = $cell_propose->sheft_id . '.2' . $col;
                //     }
                // }
                $cell_propose = Cell::where('number_of_bin', 0)->orderBy('id')->first();
                if ($cell_propose) {
                    $cell_propose->product_id = $product->id;
                    $cell_propose->save();
                    $data->vi_tri_de_xuat = $cell_propose->id;
                } else {
                    return $this->failure('', 'Không còn vị trí phù hợp');
                }
            }
        }
        return $this->success([$data]);
    }
    public function importWareHouse(Request $request)
    {
        $input = $request->all();
        $cell = Cell::find($input['cell_id']);
        $lot = Lot::find($input['lot_id']);
        $product_id = $lot->product_id;
        $number_of_bin = $cell->number_of_bin + 1;
        $cell->lot()->attach($input['lot_id']);
        Cell::find($input['cell_id'])->update(['product_id' => $product_id, 'number_of_bin' => $number_of_bin]);
        $check_lot = DB::table('cell_lot')->where('lot_id', $lot->p_id)->first();
        if (!$check_lot) {
            $input['type'] = 1;
            $input['created_by'] = $request->user()->id;
            $input['so_luong'] = $lot->so_luong;
            WareHouseLog::create($input);
        }
        return $this->success([], 'Nhập kho thành công');
    }
    public function infoImportWareHouse()
    {
        $records = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->get();
        $lot_ids = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->pluck('lot_id')->toArray();
        $lo_sx = Lot::whereIn('id', $lot_ids)->pluck('lo_sx')->toArray();
        $tong_ma_hang = ProductionPlan::whereIn('lo_sx', $lo_sx)->distinct()->count('product_id');
        $so_luong = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->sum('so_luong');
        $data = [];
        $labels = ['Tổng số thùng', 'Tổng số mã nhập kho', 'Số lượng'];
        $values = [count($records), $tong_ma_hang, $so_luong];
        foreach ($labels as $key => $label) {
            $object = new stdClass();
            $object->title = $label;
            $object->value = $values[$key];
            $data[] = $object;
        }
        return $this->success($data);
    }
    public function listImportWareHouse()
    {
        $records = WareHouseLog::whereDate('created_at', date('Y-m-d'))->where('type', 1)->orderBy('created_at', 'DESC')->get();
        $data = [];
        foreach ($records as $key => $record) {
            $object = new stdClass();
            $lot = Lot::find($record->lot_id);
            $object->thoi_gian_nhap = date('d/m/Y H:i:s', strtotime($record->created_at));
            $object->lo_sx = $lot->lo_sx;
            $object->lot_id = $record->lot_id;
            $object->ten_san_pham = $lot->product->name;
            $object->so_luong = $lot->so_luong;
            $object->vi_tri = $record->cell_id;
            $object->status = 2;
            $object->nguoi_nhap = CustomUser::find($record->created_by)->name;
            $data[] = $object;
        }
        return $this->success($data);
    }
    public function listCustomerExport()
    {
        $product_ids = WareHouseExportPlan::whereDate('ngay_xuat_hang', date('Y-m-d'))->get()->unique('product_id')->pluck('product_id')->toArray();

        $customers = Customer::select('id as value', 'name as label')->whereIn('id', Product::whereIn('id', $product_ids)->pluck('customer_id')->toArray())->get();
        return $this->success($customers);
    }
    public function getProposeExport(Request $request)
    {
        $khach_hang = $request->khach_hang;
        $product_ids = Product::where('customer_id', $khach_hang)->pluck('id')->toArray();
        $records = WareHouseExportPlan::whereIn('product_id', $product_ids)
            ->whereDate('ngay_xuat_hang', date('Y-m-d'))
            ->where(function ($query) {
                $query->whereColumn('sl_yeu_cau_giao', '>', 'sl_thuc_xuat')
                    ->orWhereNull('sl_thuc_xuat');
            })->get();
        $data = [];
        $lot_arr = [];
        foreach ($records as $key => $record) {
            $cell_ids = Cell::where('product_id', $record->product_id)->pluck('id')->toArray();
            $cell_lots = DB::table('cell_lot')->whereIn('cell_id', $cell_ids)->orderBy('created_at', 'ASC')->get();
            if (count($cell_lots) == 0) {
                $object = new stdClass();
                $object->product_id = $record->product ? $record->product->id : '';
                $object->ten_san_pham = $record->product ? $record->product->name : '';
                $object->lot_id = 'Không có tồn';
                $object->ke_hoach_xuat = $record->sl_yeu_cau_giao;
                $object->thuc_te_xuat = $record->sl_thuc_xuat;
                $object->vi_tri = '-';
                $object->so_luong =  '-';
                $object->pic = '-';
                $data[] = $object;
            }
            $product = Product::find($record->product_id);
            $dinh_muc = 0;
            foreach ($cell_lots as $key => $cell_lot) {
                if (in_array($cell_lot->lot_id, $lot_arr)) {
                    continue;
                } else {
                    $lot_arr[] = $cell_lot->lot_id;
                }
                if ($dinh_muc <  ($record->sl_yeu_cau_giao - $record->sl_hang_le - $record->sl_thuc_xuat)) {
                    $lot = Lot::find($cell_lot->lot_id);
                    if ($lot->so_luong < $product->dinh_muc_thung) continue;
                    $object = new stdClass();
                    $object->product_id = $record->product->id;
                    $object->ten_san_pham = $record->product->name;
                    $object->lot_id = $cell_lot->lot_id;
                    $object->ke_hoach_xuat = $record->sl_yeu_cau_giao;
                    $object->thuc_te_xuat = $record->sl_thuc_xuat;
                    $object->vi_tri = $cell_lot->cell_id;
                    $object->so_luong =  $lot->so_luong;
                    $object->pic = '';
                    $data[] = $object;
                    $dinh_muc = $dinh_muc + $lot->so_luong;
                }
            }
            if ($record->sl_hang_le > 0 && $dinh_muc < ($record->sl_yeu_cau_giao - $record->sl_thuc_xuat)) {
                $lot_ids = Lot::where('product_id', $record->product_id)->where('so_luong', $record->sl_hang_le)->pluck('id');
                if ($lot_ids) {
                    $object = new stdClass();
                    $cell_lot1 = DB::table('cell_lot')->whereIn('lot_id', $lot_ids)->first();
                    if ($cell_lot1) {
                        $lot_le = Lot::find($cell_lot1->lot_id);
                        if ($cell_lot1) {
                            $object->product_id = $record->product->id;
                            $object->ten_san_pham = $record->product->name;
                            $object->lot_id = $lot_le->id;
                            $object->ke_hoach_xuat = $record->sl_yeu_cau_giao;
                            $object->thuc_te_xuat =  $record->sl_thuc_xuat;
                            $object->vi_tri =  $cell_lot1->cell_id;
                            $object->so_luong =  $lot_le->so_luong;
                            $object->pic = '';
                            $data[] = $object;
                        }
                    }
                }
            }
        }
        return $this->success($data);
    }
    public function exportWareHouse(Request $request)
    {
        $input = $request->all();
        $cell_lot = DB::table('cell_lot')->where('lot_id', $input['lot_id'])->where('cell_id', $input['cell_id'])->first();
        if (!$cell_lot) {
            return $this->failure([], 'Đã xuất kho');
        }
        $cell = Cell::find($input['cell_id']);
        $lot = Lot::find($input['lot_id']);
        if ($cell->number_of_bin == 1) {
            $cell->update(['product_id' => null]);
        }
        $number_of_bin = $cell->number_of_bin - 1;
        $cell->lot()->detach($input['lot_id']);
        Cell::find($input['cell_id'])->update(['number_of_bin' => $number_of_bin]);
        $record = WareHouseExportPlan::where('khach_hang', $input['khach_hang'])->where('product_id', $lot->product_id)->whereDate('ngay_xuat_hang', date('Y-m-d'))->first();
        if ($record) {
            $sl = $lot->so_luong + $record->sl_thuc_xuat;
            $record->update(['sl_thuc_xuat' => $sl]);
        }
        $input['type'] = 2;
        $input['created_by'] = $request->user()->id;
        $input['so_luong'] = $lot->so_luong;
        WareHouseLog::create($input);
        return $this->success([], 'Xuất kho thành công');
    }
    public function infoExportWareHouse()
    {
        $sum_so_luong_kh = WareHouseExportPlan::whereDate('created_at', date('Y-m-d'))->sum('sl_yeu_cau_giao');
        $sum_so_luong_tt = WareHouseExportPlan::whereDate('created_at', date('Y-m-d'))->sum('sl_thuc_xuat');
        $ti_le = $sum_so_luong_kh != 0 ? number_format(($sum_so_luong_tt * 100) / $sum_so_luong_kh) . ' %' : 0;
        $values = [$sum_so_luong_kh, $sum_so_luong_tt, $ti_le];
        $labels = ['Kế hoạch xuất', 'Sản lượng', 'Tỷ lệ'];
        $data = [];
        foreach ($labels as $key => $label) {
            $object = new stdClass();
            $object->title = $label;
            $object->value = $values[$key];
            $data[] = $object;
        }
        return $this->success($data);
    }
    public function listLogMaterial(Request $request)
    {
        $data = MaterialExportLog::whereDate('created_at', '>=', date('Y-m-d', strtotime($request->start_date)))
            ->whereDate('created_at', '<=', date('Y-m-d', strtotime($request->end_date)))
            ->whereColumn('sl_kho_xuat', '>', 'sl_thuc_te')->get();
        return $this->success($data);
    }
    public function updateLogMaterial(Request $request)
    {
        $input = $request->all();
        foreach ($input['log'] as $key => $value) {
            $log = MaterialExportLog::find($value['id']);
            $sl = $value['sl_thuc_xuat'] ? $value['sl_thuc_xuat'] : 0;
            $sl_thuc_te = $log->sl_thuc_te + $sl;
            $log->update(['sl_thuc_te' => $sl_thuc_te]);
        }
        return $this->success([], 'Nhập thành công');
    }

    public function updateLogMaterialRecord(Request $request)
    {
        $input = $request->all();
        MaterialExportLog::find($input['id'])->update($input);
        return $this->success([], 'Cập nhật thành công');
    }

    public function listLsxUseMaterial(Request $request)
    {
        $input = $request->all();
        $id = $input['id'];
        $material_id = $input['material_id'];
        $sl_thuc_te = $input['sl_thuc_te'];
        $product_ids = Product::where('material_id', $material_id)->pluck('id')->toArray();
        $product_plans = ProductionPlan::whereDate('ngay_sx', '>=', date('Y-m-d'))->where('cong_doan_sx', 'in')->whereIn('product_id', $product_ids)->orderBy('thu_tu_uu_tien', 'ASC')->get();
        $data = [];
        foreach ($product_plans as $key => $product_plan) {
            $object = new stdClass;
            $object->id = $id;
            $object->product_id = $product_plan->product_id;
            $object->ten_san_pham = $product_plan->product->name;
            $object->lo_sx = $product_plan->lo_sx;
            $object->sl_ke_hoach = $product_plan->sl_nvl;
            $object->sl_pallet = count($product_plan->loSX);
            $object->pallet = $product_plan->loSX;
            $data[] = $object;
            $sl_thuc_te = $sl_thuc_te - $product_plan->sl_nvl;
        }
        return $this->success($data);
    }
    public function splitBarrel(Request $request)
    {
        $input = $request->all();
        $lot = Lot::find($input['lot_id']);
        $lot->update(['so_luong' => $input['remain_quanlity']]);
        $count_lot = Lot::where('p_id', $input['lot_id'])->count();
        $new_lot = new Lot();
        $new_lot->id = $lot->id . '.TC' . ($count_lot + 1);
        $new_lot->type = $lot->type;
        $new_lot->lo_sx = $lot->lo_sx;
        $new_lot->so_luong = $input['export_quanlity'];
        $new_lot->finished = 0;
        $new_lot->product_id = $lot->product_id;
        $new_lot->material_export_log_id = '';
        $new_lot->p_id = $lot->id;
        $new_lot->save();
        //
        $data = [];
        $tem_lot = new stdClass();
        $tem_lot->product_id = $lot->product_id;
        $tem_lot->so_luong = $lot->so_luong;
        $tem_lot->ver_his = '';
        $tem_lot->lo_sx = $lot->lo_sx;
        $tem_lot->cd_thuc_hien = 'Kho thành phẩm';
        $tem_lot->tg_sx = $lot->plan->thoi_gian_bat_dau;
        $tem_lot->ngay_sx = date('d/m/Y', strtotime($lot->plan->ngay_sx));
        $tem_lot->lot_id = $lot->id;
        $tem_lot->cd_tiep_theo = 'Kho thành phẩm';
        $tem_lot->nguoi_sx = '';
        $data[] = $tem_lot;
        //
        $tem_new_lot = new stdClass();
        $tem_new_lot->product_id = $new_lot->product_id;
        $tem_new_lot->so_luong = $new_lot->so_luong;
        $tem_new_lot->ver_his = '';
        $tem_new_lot->lo_sx = $new_lot->lo_sx;
        $tem_new_lot->cd_thuc_hien = 'Kho thành phẩm';
        $tem_new_lot->tg_sx = $new_lot->plan->thoi_gian_bat_dau;
        $tem_new_lot->ngay_sx = date('d/m/Y', strtotime($new_lot->plan->ngay_sx));
        $tem_new_lot->lot_id = $lot->id . '.TC' . ($count_lot + 1);
        $tem_new_lot->cd_tiep_theo = 'Kho thành phẩm';
        $tem_new_lot->nguoi_sx = '';
        $data[] = $tem_new_lot;
        return $this->success($data);
    }
    public function getHistoryWareHouse(Request $request)
    {
        $input = $request->all();
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $warehouse_log_query = WareHouseLog::select('*');
        if (isset($input['date']) && count($input['date']) > 1) {
            $warehouse_log_query->whereDate('created_at', '>=', date('Y-m-d', strtotime($input['date'][0])))->whereDate('created_at', '<=', date('Y-m-d', strtotime($input['date'][1])));
        }

        $lot_ids = $warehouse_log_query->pluck('lot_id')->toArray();
        $lot_query  = Lot::whereIn('id', $lot_ids);
        if (isset($input['khach_hang'])) {
            $lot_query->whereHas('product', function ($product_query) use ($input) {
                $product_query->where('customer_id', 'like', "%" . $input['khach_hang'] . "%");
            });
        }
        if (isset($input['lo_sx'])) {
            $lot_query->where('id', 'like', '%' . $input['lo_sx'] . '%');
        }
        if (isset($input['ten_sp'])) {
            $lot_query->where('id', 'like', '%' . $input['ten_sp'] . '%');
        }
        $count = $lot_query->count();
        $lots = $lot_query->offset($page * $pageSize)->limit($pageSize)->get();
        $totalPage = $count;
        $data = [];
        foreach ($lots as $key => $lot) {
            $log_import = WareHouseLog::with('creator')->where('lot_id', $lot->id)->where('type', 1)->first();
            $log_export = WareHouseLog::with('creator')->where('lot_id', $lot->id)->where('type', 2)->first();
            $object = new stdClass();
            $object->ngay = $log_import ? date('d/m/Y', strtotime($log_import->created_at)) : '';
            $object->ma_khach_hang = $lot->product->customer->id ?? "";
            $object->ten_khach_hang = $lot->product->customer->name ?? "";
            $object->product_id = $lot->product_id;
            $object->ten_san_pham = $lot->product->name ?? "";
            $object->dvt = 'Mảnh';
            $object->lo_sx = $lot->lo_sx;
            $object->vi_tri = $log_import ? $log_import->cell_id : '';
            $object->kho = 'KTP';
            $object->lot_id = $lot->id;
            $object->ngay_nhap = $log_import ? date('d/m/Y H:i:s', strtotime($log_import->created_at)) : '';
            $object->so_luong_nhap  = $log_import ? $log_import->so_luong : 0;
            $object->nguoi_nhap  = $log_import ? $log_import->creator->name : '';
            $object->ngay_xuat = $log_export ? date('d/m/Y H:i:s', strtotime($log_export->created_at)) : '';
            $object->so_luong_xuat  = $log_export ? $log_export->so_luong : 0;
            $object->nguoi_xuat  = $log_export ? $log_export->creator->name : '';
            $object->ton_kho = $object->so_luong_nhap - $object->so_luong_xuat;
            $object->so_ngay_ton = !$log_export ? ((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($log_import->created_at)))) / 86400) : '';
            $data[] = $object;
        }
        $record = new stdClass();
        $record->data = $data;
        $record->totalPage = $totalPage;
        return $this->success($record);
    }
    public function destroyPallet(Request $request)
    {
        $input = $request->all();
        foreach ($input as $key => $value) {
            if (is_numeric($value)) {
                MaterialExportLog::where('id', $value)->delete();
            } else {
                Lot::where('id', $value)->delete();
            }
        }
        return $this->success([], 'Xóa thành công');
    }
    public function storeLogMaterial(Request $request)
    {
        $input = $request->all();
        MaterialExportLog::create($input);
        return $this->success([], 'Thêm mới thành công');
    }
    public function destroyProductPlan(Request $request)
    {
        $input = $request->all();
        foreach ($input as $key => $value) {
            ProductionPlan::where('id', $value)->delete();
        }
        return $this->success([], 'Xóa thành công');
    }
    public function storeProductPlan(Request $request)
    {
        $input = $request->all();
        $input['cong_doan_sx'] = Str::slug($input['cong_doan_sx']);
        $check = ProductionPlan::where('lo_sx', $input['lo_sx'])->where('cong_doan_sx', $input['cong_doan_sx'])->first();
        if ($check) {
            return $this->failure([], 'Trùng lô sản xuất');
        } else {
            ProductionPlan::create($input);
            return $this->success([], 'Thêm thành công');
        }
    }
    public function updateProductPlan(Request $request)
    {
        $input = $request->all();
        $input['cong_doan_sx'] = Str::slug($input['cong_doan_sx']); //
        ProductionPlan::find($input['id'])->update($input);
        return $this->success([], 'Cập nhật thành công');
    }

    public function updateSanLuong(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $info_cong_doan = $pallet->infoCongDoan()->where('line_id', $line->id)->first();
        $info_cong_doan['sl_dau_ra_hang_loat'] = $request->san_luong * $pallet->plan->product->so_bat;
        $info_cong_doan->save();
        return $this->success([], 'Cập nhật thành công');
    }

    public function checkSanLuong(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $info_cong_doan = $pallet->infoCongDoan()->where('line_id', $line->id)->first();
        if (isset($info_cong_doan['sl_dau_ra_hang_loat']) && $info_cong_doan['sl_dau_ra_hang_loat']) {
            return $this->failure([], 'Đã nhập sản lượng');
        } else {
            return $this->success([], '');
        }
    }
    public function destroyWareHouseExport(Request $request)
    {
        $input = $request->all();
        foreach ($input as $key => $value) {
            WareHouseExportPlan::where('id', $value)->delete();
        }
        return $this->success([], 'Xóa thành công');
    }
    public function createWareHouseExport(Request $request)
    {
        $input = $request->all();
        WareHouseExportPlan::create($input);
        return $this->success([], 'Thêm thành công');
    }
    public function updateWareHouseExport(Request $request)
    {
        $input = $request->all();
        WareHouseExportPlan::find($input['id'])->update($input);
        return $this->success([], 'Cập nhật thành công');
    }

    public function batDauTinhSanLuong(Request $request)
    {
        $pallet = Lot::find($request->lot_id);
        if (!isset($pallet)) {
            return $this->failure([], "Không tìm thấy pallet");
        }
        $line = Line::find($request->line_id);
        if (!isset($line)) {
            return $this->failure([], "Không tìm thấy công đoạn");
        }
        $info_cong_doan = $pallet->infoCongDoan()->where('line_id', $line->id)->first();
        if (!$info_cong_doan->thoi_gian_bam_may) {
            $info_cong_doan['thoi_gian_bam_may'] = Carbon::now();
            $info_cong_doan->save();
            return $this->success($info_cong_doan->thoi_gian_bam_may, 'Bắt đầu tinh sản lượng');
        } else {
            return $this->failure(null, 'Đã bắt đàu tính sản lượng');
        }
    }

    public function prepareGT(Request $request)
    {
        $thung1 = Lot::where('id', $request->thung1)->where('type', 2)->whereExists(function ($query) {
            $query->select("cell_lot.lot_id")
                ->from('cell_lot')
                ->whereRaw('lot.id = cell_lot.lot_id');
        })->first();
        $thung2 = Lot::where('id', $request->thung2)->where('type', 2)->whereExists(function ($query) {
            $query->select("cell_lot.lot_id")
                ->from('cell_lot')
                ->whereRaw('lot.id = cell_lot.lot_id');
        })->first();
        $text = '';
        $data = [
            'thung1' => $thung1 ? $thung1->id : '',
            'sl_thung1' => $thung1 ? $thung1->so_luong : 0,
            'thung2' => $thung2 ? $thung2->id : '',
            'sl_thung2' => $thung2 ? $thung2->so_luong : 0
        ];
        if ($thung1 && $thung2) {
            if ($thung1->id === $thung2->id) {
                return $this->failure([
                    'thung1' => '',
                    'sl_thung1' => 0,
                    'thung2' => '',
                    'sl_thung2' => 0
                ], 'Không gộp cùng một mã thùng');
            }
            if ($thung1->product_id !== $thung2->product_id) {
                return $this->failure([
                    'thung1' => '',
                    'sl_thung1' => 0,
                    'thung2' => '',
                    'sl_thung2' => 0
                ], 'Phải gộp thùng có cùng mã sản phẩm');
            }
        }
        return $this->success($data, $text);
    }

    public function gopThungIntem(Request $request)
    {
        $thung1 = Lot::where('id', $request->thung1)->where('type', 2)->first();
        $thung2 = Lot::where('id', $request->thung2)->where('type', 2)->first();
        if (!$thung1 || !$thung2) {
            return $this->failure(null, 'Không tìm thấy thùng');
        } else {
            if ($thung1->id === $thung2->id) {
                return $this->failure([], 'Không gộp cùng một mã thùng');
            }
            if ($thung1->product_id !== $thung2->product_id) {
                return $this->failure([], 'Phải gộp thùng có cùng mã sản phẩm');
            }
            $thung1['so_luong'] = $thung1['so_luong'] + $request->sl_gop;
            $thung1->save();
            $thung2['so_luong'] = $thung2['so_luong'] - $request->sl_gop;
            $thung2->save();

            $data = [];
            $tem_lot = new stdClass();
            $tem_lot->product_id = $thung1->product_id;
            $tem_lot->so_luong = $thung1->so_luong;
            $tem_lot->ver_his = '';
            $tem_lot->lo_sx = $thung1->lo_sx;
            $tem_lot->cd_thuc_hien = 'Kho thành phẩm';
            $tem_lot->tg_sx = $thung1->plan->thoi_gian_bat_dau;
            $tem_lot->ngay_sx = date('d/m/Y', strtotime($thung1->plan->ngay_sx));
            $tem_lot->lot_id = $thung1->id;
            $tem_lot->cd_tiep_theo = 'Kho thành phẩm';
            $tem_lot->nguoi_sx = '';
            $data[] = $tem_lot;
            //
            if ($thung2->so_luong > 0) {
                $tem_new_lot = new stdClass();
                $tem_new_lot->product_id = $thung2->product_id;
                $tem_new_lot->so_luong = $thung2->so_luong;
                $tem_new_lot->ver_his = '';
                $tem_new_lot->lo_sx = $thung2->lo_sx;
                $tem_new_lot->cd_thuc_hien = 'Kho thành phẩm';
                $tem_new_lot->tg_sx = $thung2->plan->thoi_gian_bat_dau;
                $tem_new_lot->ngay_sx = date('d/m/Y', strtotime($thung2->plan->ngay_sx));
                $tem_new_lot->lot_id = $thung2->id;
                $tem_new_lot->cd_tiep_theo = 'Kho thành phẩm';
                $tem_new_lot->nguoi_sx = '';
                $data[] = $tem_new_lot;
            } else {
                $cell_lot = DB::table('cell_lot')->where('lot_id', $thung2->id)->first();
                $cell = Cell::find($cell_lot->cell_id);
                $number_of_bin = $cell->number_of_bin > 0 ? $cell->number_of_bin - 1 : 0;
                $cell->update(['number_of_bin' => $number_of_bin]);
                DB::table('cell_lot')->where('lot_id', $thung2->id)->delete();
            }

            return $this->success($data);
        }
    }

    public function updateWarehouseEportPlan(Request $request)
    {
        $user = auth('admin')->user();
        return $this->success($user, '');
    }

    public function listScenario()
    {
        $records = Scenario::all();
        return $this->success($records);
    }
    public function updateScenario(Request $request)
    {
        $input = $request->all();
        Scenario::find($input['id'])->update($input);
        return $this->success([], 'Cập nhật thành công');
    }
    public function historyMonitor(Request $request)
    {
        $input = $request->all();
        $query = Monitor::with('machine')->orderBy('created_at', 'DESC');
        if (isset($input['type'])) {
            $query = $query->where('type', $input['type']);
        }
        if (isset($input['machine_id'])) {
            $query = $query->where('machine_id', $input['machine_id']);
        }
        if (isset($input['status'])) {
            $query = $query->where('status', $input['status']);
        }
        if (isset($input['start_date'])) {
            $query = $query->whereDate('created_at', '>=', $input['start_date']);
        } else {
            $query = $query->whereDate('created_at', '>=', date('Y-m-d'));
        }
        if (isset($input['end_date'])) {
            $query = $query->whereDate('created_at', '<=', $input['end_date']);
        } else {
            $query = $query->whereDate('created_at', '<=', date('Y-m-d'));
        }
        $records = $query->get();
        return $this->success($records);
    }
    public function updateMonitor(Request $request)
    {
        $input = $request->all();
        $input['created_by'] = $request->user()->id;
        Monitor::find($input['id'])->update($input);
        return $this->success([], 'Cập nhật thành công');
    }
    public function ui_getLines(Request $request)
    {
        $lines = Line::with('machine')->get();
        return $this->success($lines);
    }

    public function getTreeLines(Request $request)
    {
        $lines = Line::where('display', 1)->with(['children' => function ($query) {
            $query->select('machines.*', 'id as key', 'name as title', DB::raw("'machine' as type"));
        }])->select('lines.*', 'id as key', 'name as title', DB::raw("'line' as type"))->get();
        $data = [
            [
                'key' => 'xuong_giay',
                'title' => 'Xưởng Giấy',
                'type' => 'phan_xuong',
                'children' => $lines
            ]
        ];
        return $this->success($data);
    }

    public function ui_getLineListMachine(Request $request)
    {
        if (isset($request->line)) {
            $line = Line::find($request->line);
            return $this->success($line->machine);
        } else {
            $machine = Machine::select('id', 'code', 'name')->get();
            return $this->success($machine);
        }
    }
    public function ui_getProducts(Request $request)
    {
        return $this->success(Product::all());
    }
    public function ui_getStaffs(Request $request)
    {
        $list = Workers::all();
        return $this->success($list);
    }

    public function ui_getLoSanXuat()
    {
        return $this->success(ProductionPlan::all()->pluck('lo_sx'));
    }

    public function ui_getErrors()
    {
        return $this->success(Error::where('noi_dung', '<>', '')->get());
    }

    public function ui_getErrorsMachine()
    {
        return $this->success(ErrorMachine::all());
    }
    public function ui_getCustomers(Request $request)
    {
        return $this->success(Customer::all());
    }
    public function uiThongSoMay(Request $request)
    {
        $page = $request->page - 1;
        $pageSize = $request->pageSize;
        $query = ThongSoMay::with('machine', 'lot.product.machinespec');
        $line = Line::find($request->line_id);
        if ($line) {
            $query->where('line_id', $line->id);
        }
        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        if (isset($request->lo_sx)) {
            $query->where('lo_sx', $request->lo_sx);
        }
        if (isset($request->date) && count($request->date) === 2) {
            $query->whereDate('ngay_sx', '>=', date('Y-m-d 00:00:00', strtotime($request->date[0])))
                ->whereDate('ngay_sx', '<=', date('Y-m-d 23:59:59', strtotime($request->date[1])));
        } else {
            $query->whereDate('ngay_sx', '>=', date('Y-m-d'))
                ->whereDate('ngay_sx', '<=', date('Y-m-d'));
        }
        if (isset($request->ca_sx)) {
            $query->where('ca_sx', $request->ca_sx);
        }
        if (isset($request->date_if)) {
            $query->whereDate('date_if', date('Y-m-d', strtotime($request->date_if)));
        }
        if (isset($request->date_input)) {
            $query->whereDate('date_input', date('Y-m-d', strtotime($request->date_input)));
        }
        $count = $query->count();
        $totalPage = $count;
        $thong_so_may = $query->offset($page * $pageSize)->limit($pageSize)->get();
        $machine_query = Machine::with(['parameters' => function ($query) {
            $query->select('parameters.*', 'machine_parameters.is_if');
        }]);
        if ($line) {
            $machine_query->where('line_id', $line->id);
        }
        if (isset($request->machine_code)) {
            $machine_query->where('code', $request->machine_code);
        }
        $machines = $machine_query->get();
        $columns = [];
        foreach ($machines as $machine) {
            foreach ($machine->parameters as $param) {
                $col = new stdClass;
                $col->title = $param->name;
                $col->dataIndex = $param->id;
                $col->key = $param->id;
                $col->is_if = $param->is_if;
                if (!isset($columns[$param->id])) {
                    $columns[$param->id] = $col;
                }
            }
        }
        $machine_parameter_logs = MachineParameterLogs::where('machine_id', $request->machine_id)->whereDate('start_time', date('Y-m-d'))->get();
        foreach ($machine_parameter_logs as $machine_params) {
            $data[] = array_merge($machine_params->data_if ?? [], $machine_params->data_input ?? [], ['start_time' => $machine_params->start_time, 'end_time' => $machine_params->end_time]);
        }
        $obj = new stdClass;
        $obj->columns = $columns;
        foreach ($thong_so_may as $record) {
            $data_if = $record->data_if;
            if (!is_null($record->lot_id) && $record->line_id == 13) {
                $value = $record->lot->product->machinespec->value ?? 'v';
                if (isset($data_if['t_gun']) && str_replace(',', '', $data_if['t_gun']) > 6000) {
                    $data_if['t_gun'] = strval(rand(165 * 10, 175 * 10) / 10);
                }
                if ($value && $value == 'v') {
                    $data_if['t_gun'] = '-';
                } else {
                    $data_if['p_gun'] = '-';
                }
            }
            $record->data_if = $data_if;
        }
        $obj->data = $thong_so_may;
        $obj->totalPage = $totalPage;
        return $this->success($obj);
    }
    public function ui_getMachines(Request $request)
    {
        return $this->success(Machine::all());
    }
    function getMachineParameters(Request $request)
    {
        $machine = Machine::with(['parameters' => function ($query) {
            $query->select('parameters.*', 'machine_parameters.is_if');
        }])->where('code', $request->machine_id)->first();
        $columns = [];
        foreach ($machine->parameters as $param) {
            $col = new stdClass;
            $col->title = $param->name;
            $col->dataIndex = $param->id;
            $col->key = $param->id;
            $col->is_if = $param->is_if;
            $columns[] = $col;
        }

        $machine_parameter_logs = MachineParameterLogs::where('machine_id', $request->machine_id)->whereDate('start_time', date('Y-m-d'))->get();
        $data = [];
        foreach ($machine_parameter_logs as $machine_params) {
            $data[] = array_merge($machine_params->data_if ?? [], $machine_params->data_input ?? [], ['start_time' => $machine_params->start_time, 'end_time' => $machine_params->end_time]);
        }
        $obj = new stdClass;
        $obj->columns = $columns;
        $obj->data = $data;
        return $this->success($obj);
    }

    public function updateMachineParameters(Request $request)
    {
        $date = date('Y-m-d H:i:s', strtotime($request->date));
        $machine_parameter_logs = MachineParameterLogs::where('machine_id', $request->machine_id)
            ->where(function ($query) use ($date) {
                $query->where('start_time', '<=', $date)
                    ->where('end_time', '>=', $date);
            })
            ->first();
        if ($machine_parameter_logs) {
            $key = $request->key;
            $input = $machine_parameter_logs['data_input'];
            $input[$key] = $request->value;
            $machine_parameter_logs['data_input'] = $input;
            $machine_parameter_logs->save();
        }
        $tsm = ThongSoMay::where('machine_code', $request->machine_id)->orderBy('created_at', 'DESC')->first();
        if ($tsm) {
            $key = $request->key;
            $input = $tsm->data_input;
            $input[$key] = $request->value;
            $tsm['data_input'] = $input;
            $tsm['date_input'] = Carbon::now();
            $tsm->save();
        }
        return $this->success($machine_parameter_logs);
    }

    public function detailLot(Request $request)
    {
        $input = $request->all();
        $cell_lot = DB::table('cell_lot')->where('lot_id', $input['lot_id'])->first();
        if (!$cell_lot) {
            return $this->failure([], 'Chưa nhập kho');
        }
        $lot = Lot::find($input['lot_id']);
        $object = new stdClass();
        $object->lot_id = $lot->id;
        $object->product_id = $lot->product_id;
        $object->ten_san_pham = $lot->product->name;
        $object->so_luong = $lot->so_luong;
        $object->vi_tri = $cell_lot->cell_id;
        return $this->success($object);
    }
    public function infoChon(Request $request)
    {
        $input = $request->all();
        $lot = Lot::find($input['lot_id']);
        $log = $lot->log;
        $info = $log->info;
        $object = new stdClass();
        if (!isset($info['chon']['table'])) {
            return $this->failure([], 'Chưa giao việc');
        } else {
            $sl_ok = 0;
            foreach ($info['chon']['table'] as $key => $value) {
                $sl_ok += isset($value['so_luong_thuc_te_ok']) ? $value['so_luong_thuc_te_ok'] : 0;
            }
            $object->sl_ok = $sl_ok - $info['chon']['sl_in_tem'];
            $object->sl_ton = OddBin::where('product_id', $lot->product_id)->where('lo_sx', $lot->lo_sx)->sum('so_luong');
        }
        return $this->success($object);
    }
    public function statusIOT()
    {
        $status = 1;
        return $this->success($status);
    }
    public function taoTem(Request $request)
    {
        $input = $request->all();
        $count = Lot::where('lo_sx', $input['lo_sx'])->where('product_id', $input['product_id'])->where('type', 2)->count();
        $data = [];
        $product = Product::find($input['product_id']);
        for ($i = 1; $i <= $input['number_bin']; $i++) {
            $obj = new Lot();
            $obj->id = $input['lo_sx'] . '.' . $input['product_id'] . '.pl1-T' . ($count + $i);
            $obj->so_luong = $input['so_luong'];
            $obj->lo_sx = $input['lo_sx'];
            $obj->type = 2;
            $obj->product_id = $input['product_id'];
            $obj->save();
            $obj->product_id = $product->name;
            $obj->lot_id = $obj->id;
            $obj->ngay_sx = date('d/m/Y');
            $obj->tg_sx = date('d/m/Y H:i:s');
            $data[] = $obj;
        }
        return $this->success($data);
    }
    public function listProduct()
    {
        $records = Product::select('name as label', 'id as value')->get();
        return $this->success($records);
    }
    public function updateDuLieu()
    {
        $linex = [
            "kho_bao_on" => 9,
            "in" => 10,
            "phu" => 11,
            "be" => 12,
            "gap-dan" => 13,
            "boc" => 14,
            "chon" => 15,
            "u" => 21,
            "in-luoi" => 22
        ];
        //Update info công đoạn
        $info_cds = InfoCongDoan::all();
        foreach ($info_cds as $info_cd) {
            $lot = Lot::find($info_cd->lot_id);
            if ($lot) {
                $info_cd->update(['lo_sx' => $lot->lo_sx]);
            }
        }
        //Update kế hoạch sản xuất
        $product_plans = ProductionPlan::all();
        foreach ($product_plans as $product_plan) {
            if ($linex[$product_plan->cong_doan_sx]) {
                $product_plan->update(['line_id' => $linex[$product_plan->cong_doan_sx]]);
            }
        }
        //Update sản phẩm
        $products = Product::all();
        foreach ($products as $product) {
            if ($product->spec) {
                $spec = $product->spec->where('slug', 'so-bat')->first();
                $product->update(['so_bat' => $spec->value]);
            }
        }
        return $this->success([], 'Update thành công');
    }
}
