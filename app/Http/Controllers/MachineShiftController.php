<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineShift;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MachineShiftController extends Controller
{
    use API;
    public function store(Request $request)
    {
        // Validate dữ liệu từ frontend
        $validated = $request->validate([
            'machines' => 'required|array|min:1', // Danh sách máy (mảng)
            'machines.*' => 'exists:machines,code', // Mỗi máy phải tồn tại trong bảng machines
            'shifts' => 'required|array|min:1', // Danh sách ca (mảng)
            'shifts.*' => 'exists:shifts,id', // Mỗi ca phải tồn tại trong bảng shifts
            'dateRange' => 'required|array', // Dải thời gian (start_time, end_time)
            'dateRange.0' => 'required|date', // Đảm bảo đây là chuỗi ngày hợp lệ
            'dateRange.1' => 'required|date', // Đảm bảo đây là chuỗi ngày hợp lệ
        ]);

        // Trích xuất phần date từ timeRange (lấy ngày bắt đầu của dải thời gian)
        $startDate = Carbon::parse($validated['dateRange'][0])->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d'); // Chuyển đổi định dạng từ ISO 8601 sang Y-m-d
        $endDate = Carbon::parse($validated['dateRange'][1])->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d'); // Tương tự cho ngày kết thúc

        // Bắt đầu giao dịch database
        DB::beginTransaction();

        try {
            // Lặp qua các máy và ca để lưu dữ liệu vào bảng machine_shift
            foreach ($validated['machines'] as $machineId) {
                foreach ($validated['shifts'] as $shiftId) {
                    // Lưu dữ liệu theo từng ngày trong khoảng thời gian
                    $currentDate = Carbon::parse($startDate);
                    while ($currentDate->lte(Carbon::parse($endDate))) {
                        MachineShift::firstOrCreate([
                            'machine_id' => $machineId,
                            'shift_id' => $shiftId,
                            'date' => $currentDate->format('Y-m-d'), // Lưu ngày
                        ]);

                        // Tăng ngày lên 1
                        $currentDate->addDay();
                    }
                }
            }

            // Commit giao dịch nếu không có lỗi
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ca làm việc đã được lưu thành công!',
            ], 201);
        } catch (\Exception $e) {
            // Rollback giao dịch nếu có lỗi
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Lỗi khi lưu ca làm việc: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        // Validate dữ liệu đầu vào
        $validated = $request->validate([
            'startDate' => 'required|date_format:Y-m-d', // Ngày bắt đầu
            'endDate' => 'required|date_format:Y-m-d', // Ngày kết thúc
        ]);

        // Lấy giá trị startDate và endDate từ request
        $startDate = Carbon::parse($validated['startDate']);
        $endDate = Carbon::parse($validated['endDate']);
        $machineId = $request->input('machine_id');

        // Truy vấn tất cả các máy
        $query = Machine::with(['machineShifts' => function ($query) use ($startDate, $endDate) {
            // Truy vấn các ca làm việc trong khoảng thời gian yêu cầu
            $query->whereBetween('date', [$startDate, $endDate])
                ->with('shift'); // Eager load tên ca
        }]);
        if ($machineId) {
            $query->where('code', $machineId);
        }
        $machines = $query->get();

        // Cấu trúc dữ liệu để trả về cho frontend
        $result = $machines->map(function ($machine) {
            return [
                'machine_id' => $machine->code,
                'machine_name' => $machine->code,
                'shifts' => $machine->machineShifts->map(function ($machineShift) {
                    return [
                        'date' => $machineShift->date,
                        'shift_name' => $machineShift->shift->name, // Lấy tên của ca
                        'shift_id' => $machineShift->shift_id,
                        'operator_quantity' => $machineShift->operator_quantity,
                    ];
                })->toArray(),
            ];
        });

        return $this->success($result);
    }
    public function update(Request $request)
    {
        // Validate dữ liệu được gửi từ frontend
        $request->validate([
            'date' => 'required|date', // Kiểm tra ngày
        ]);

        try {
            // Lấy dữ liệu từ request
            $date = $request->input('date');
            $shift_id = $request->input('shift_id');
            $machineId = $request->input('machine_id');
            $operatorQuantity = $request->input('operator_quantity');

            // // Xóa các ca làm việc hiện tại của máy trong ngày đó
            // MachineShift::where('machine_id', $machineId)
            //     ->whereDate('date', $date)
            //     ->delete();
            // // Thêm các ca làm việc mới
            // foreach ($shiftIds as $shiftId) {
            //     MachineShift::create([
            //         'machine_id' => $machineId,
            //         'date' => $date,
            //         'shift_id' => $shiftId,
            //     ]);
            // }
            MachineShift::updateOrCreate(
                ['machine_id' => $machineId, 'date' => $date, 'shift_id' => $shift_id],
                [
                    'shift_id' => $shift_id,
                    'operator_quantity' => $operatorQuantity,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật ca làm việc thành công!',
            ]);
        } catch (\Exception $e) {
            // Xử lý lỗi và trả về thông báo lỗi
            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi khi cập nhật ca làm việc.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function delete(Request $request)
    {
        try {
            // Lấy dữ liệu từ request
            $date = $request->input('date');
            $shift_id = $request->input('shift_id');
            $machineId = $request->input('machine_id');
            MachineShift::where('machine_id', $machineId)->whereDate('date', $date)->where('shift_id', $shift_id)->delete();
            return response()->json([
                'success' => true,
                'message' => 'Xoá ca làm việc thành công!',
            ]);
        } catch (\Exception $e) {
            // Xử lý lỗi và trả về thông báo lỗi
            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi khi xoá ca làm việc.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
