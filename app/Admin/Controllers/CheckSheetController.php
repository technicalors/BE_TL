<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CheckSheet;
use App\Models\CheckSheetLog;
use App\Models\CheckSheetWork;
use App\Models\Machine;
use App\Traits\API;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Hamcrest\Type\IsNumeric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckSheetController extends Controller
{
    use API;
    public function list(Request $request)
    {
        $query = CheckSheetWork::orderBy('created_at', 'DESC');
        if (isset($request->machine_id)) {
            $query->whereHas('checksheet', function ($q) use ($request) {
                $q->where('machine_id', 'like', "%$request->machine_id%");
            });
        }
        if (isset($request->hang_muc)) {
            $query->whereHas('checksheet', function ($q) use ($request) {
                $q->where('hang_muc', 'like', "%$request->hang_muc%");
            });
        }
        if (isset($request->cong_viec)) {
            $query->where('cong_viec', 'like', "%$request->cong_viec%");
        }
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            // return $request->page - 1;
            $query->offset((($request->page - 1) ?? 0) * $request->pageSize)->limit($request->pageSize);
        }
        $query->with('checksheet');
        $result = $query->get();
        foreach ($result as $key => $value) {
            $value->hang_muc = $value->checksheet->hang_muc;
            $value->machine_id = $value->checksheet->machine_id;
        }
        return $this->success(['data' => $result, 'total' => $total]);
    }

    public function create(Request $request)
    {
        $input = $request->all();
        $machine = Machine::where('code', $input['machine_id'])->first();
        if (!$machine) {
            return $this->failure($input, 'Không tìm thấy máy');
        }
        try {
            DB::beginTransaction();
            $hang_muc = Checksheet::firstOrCreate([
                'hang_muc' => $input['hang_muc'],
                'line_id' => $machine->line_id,
                'machine_id' => $machine->code,
            ]);
            $input['checksheet_id'] = $hang_muc->id;
            $checksheet = CheckSheetWork::create([
                'check_sheet_id' => $input['checksheet_id'],
                'cong_viec' => $input['cong_viec'],
                'type' => $input['type']
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
        return $this->success($checksheet, 'Tạo thành công');
    }

    public function update(Request $request, $id)
    {
        $input = $request->all();
        $machine = Machine::where('code', $input['machine_id'])->first();
        if (!$machine) {
            return $this->failure($input, 'Không tìm thấy máy');
        }
        try {
            DB::beginTransaction();
            $hang_muc = Checksheet::firstOrCreate([
                'hang_muc' => $input['hang_muc'],
                'line_id' => $machine->line_id,
                'machine_id' => $machine->code,
            ]);
            $input['checksheet_id'] = $hang_muc->id;
            $checksheet = CheckSheetWork::find($id);
            if (!$checksheet) {
                return $this->failure($id, 'Không tìm thấy cheksheet');
            }
            $checksheet->update([
                'check_sheet_id' => $input['checksheet_id'],
                'cong_viec' => $input['cong_viec'],
                'type' => $input['type']
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
        return $this->success($checksheet, 'Cập nhật thành công');
    }

    public function delete(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $checksheet = CheckSheetWork::find($id)->delete();
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
        }
        return $this->success($checksheet, 'Xoá thành công');
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
        try {
            DB::beginTransaction();
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
            // --- Thiết lập hàng tiêu đề & cột ---
            // Giữ nguyên quy ước của bạn: dòng 6 là dòng header tên máy; dữ liệu bắt đầu từ dòng 8
            $headerRowIndex  = 6; // dòng chứa tên máy
            $dataStartRow    = 8; // dòng bắt đầu dữ liệu

            if (!isset($allDataInSheet[$headerRowIndex])) {
                return $this->failure([], 'Không tìm thấy dòng tiêu đề (dòng 6) để xác định máy.', 422);
            }
            $machines = [];
            foreach ($allDataInSheet[$headerRowIndex] as $key => $ma_may) {
                if (empty($ma_may)) continue;
                $machine = Machine::where('code', $ma_may)->first();
                if ($machine) $machines[$key] = [
                    'code' => $machine->code,
                    'line_id' => $machine->line_id
                ];
                else continue;
            }
            $old_checksheet = CheckSheet::whereIn('machine_id', array_column(array_values($machines), 'code'))->get();
            foreach ($old_checksheet as $key => $value) {
                $value->checkSheetWork()->delete();
                $value->delete();
            }
            $checksheet = null;
            $input_checksheet = [];
            foreach ($allDataInSheet as $key => $row) {
                if ($key > 7 && count($machines) > 0) {
                    foreach ($machines as $machine_key => $machine) {
                        $cell = isset($row[$machine_key]) ? trim((string)$row[$machine_key]) : '';
                        // Rule 1: Bỏ qua nếu rỗng hoặc = "0"
                        if (!$cell || $cell === '' || $cell === '0' || $cell === '0.0') {
                            continue;
                        }
                        $count = 1;
                        // Lấy số dương đầu tiên trong chuỗi
                        if (preg_match('/\d+/', $cell, $m)) {
                            $count = max(1, (int)$m[0]);
                        }
                        if (!empty($row['C'])) {
                            $input_checksheet['hang_muc'] = $row['C'];
                        }
                        $input_checksheet['machine_id'] = $machine['code'];
                        $input_checksheet['line_id'] = $machine['line_id'];
                        $checksheet = CheckSheet::firstOrCreate($input_checksheet);
                        if ($checksheet) {
                            for ($i = 1; $i <= $count; $i++) {
                                $input = [];
                                $input['check_sheet_id'] = $checksheet->id;
                                $input['cong_viec'] = $row['D'];
                                CheckSheetWork::create($input);
                            }
                        }
                    }
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
            // Handle the exception and return an appropriate response
            return $this->failure(['error' => $e->getMessage()], 'File import failed', 422);
        }
        return $this->success('', 'Upload thành công');
    }

    public function export(Request $request)
    {
        return $this->success('', 'Export thành công');
    }

    public function autocomplete(Request $request)
    {
        if (empty($request->machine_ids)) {
            return $this->failure([], 'Chưa chọn máy');
        }
        foreach ($request->machine_ids ?? [] as $key => $machine_id) {
            $log = CheckSheetLog::query()
                ->where('info->machine_id', $machine_id)
                ->whereDate('created_at', now()->toDateString())
                ->first();

            if ($log) {
                continue;
            } else {
                CheckSheetLog::create([
                    'info'       => [
                        'created_by' => $request->user()->id,
                        'machine_id' => $machine_id,
                        'data'       => [],
                    ],
                    'created_at' => now()->startOfDay(),
                ]);
            }
        }
        return $this->success([], 'Đã cập nhật thành công');
    }
}
