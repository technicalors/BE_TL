<?php

namespace App\Admin\Controllers;

use App\Helpers\QueryHelper;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\InfoCongDoan;
use App\Models\Inventory;
use App\Models\Line;
use App\Models\LineInventories;
use App\Models\Losx;
use App\Models\LotPlan;
use App\Models\Machine;
use App\Models\MachineLoadFactor;
use App\Models\MachinePriorityOrder;
use App\Models\MachineShift;
use App\Models\NumberMachineOrder;
use App\Models\PrioritizedMachines;
use App\Models\Product;
use App\Models\ProductionOrderPriority;
use App\Models\ProductionPlan;
use App\Models\ProductOrder;
use App\Models\Spec;
use App\Traits\API;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductionPlanController extends AdminController
{
    use API;
    //=========================AUTO PLAN================================//
    public static function getProductionSteps($productId)
    {
        // Bước 1: Truy vấn để lấy các công đoạn từ bảng spec theo product_id và slug 'hanh-trinh-san-xuat'
        // Sắp xếp theo thứ tự giảm dần (DESC) để tính toán sản lượng
        return Spec::where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            // ->where('line_id', '<>', 29)
            ->whereRaw('value REGEXP "^[0-9]+$"')
            ->orderBy('value', 'desc')
            ->get();
    }

    function getOrderedProductionSteps($productId)
    {
        // Bước 8: Truy vấn để lấy các công đoạn từ bảng spec theo product_id và sắp xếp theo value ASC
        return Spec::with('line')->where('product_id', $productId)
            ->where('slug', 'hanh-trinh-san-xuat')
            ->whereRaw('value REGEXP "^[0-9]+$"')
            ->orderBy('value', 'asc')
            ->get();
    }

    function calculateProductionWastage($productId)
    {

        // Lấy hao phí vào hàng từ bảng spec
        $inputWaste = Spec::where('product_id', $productId)
            ->where('slug', 'hao-phi-vao-hang-cac-cong-doan')
            ->pluck('value', 'line_id')
            ->toArray();
        return (array)$inputWaste;
    }

    public static function calculateProductionOutput($productId, $lineId, $quantity)
    {
        $productionWaste = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'hao-phi-san-xuat-cac-cong-doan')
            ->first();

        $inputWaste = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'hao-phi-vao-hang-cac-cong-doan')
            ->first();
        if ($productionWaste) {
            $quantity += $quantity * ($productionWaste->value / 100);
        }

        if ($inputWaste) {
            $quantity += $inputWaste->value;
        }

        return $quantity;
    }

    function modifiedCalculateProductionOutput($lineId, $quantity, $lineProductionWaste = [], $lineInputWaste = [], $lineInventory = [])
    {
        $productionWaste = $lineProductionWaste[$lineId] ?? 0;

        $inputWaste = $lineInputWaste[$lineId] ?? 0;

        $line_inventory = $lineInventory[$lineId] ?? 0;
        $remain = $quantity - $line_inventory;
        if ($remain > 0) {
            $quantity = $remain;
        } else {
            return 0;
        }
        if ($productionWaste) {
            $quantity += Ceil($quantity * ($productionWaste / 100));
        }

        if ($inputWaste) {
            $quantity += $inputWaste;
        }

        return $quantity;
    }

    function getTransportTimeBetweenSteps($productId, $lineId)
    {
        // Truy vấn để lấy thời gian vận chuyển giữa các công đoạn từ bảng spec theo slug 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau'
        $transportTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau')
            ->first();

        return $transportTimeSpec ? $transportTimeSpec->value : 0; // Nếu không tìm thấy, trả về 0
    }
    function getLotSize($productId, $lineId)
    {
        // Truy vấn để lấy giá trị lotsize từ bảng spec theo slug 'so-luong'
        $lotSizeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'so-luong')
            ->first();

        return $lotSizeSpec ? $lotSizeSpec->value : 11000; // Nếu không tìm thấy, trả về 0
    }
    function getLotSizes($productId, array $lineIds)
    {
        $specs = Spec::whereIn('line_id', $lineIds)
            ->where('product_id', $productId)
            ->where('slug', 'so-luong')
            ->get();

        $lotSizes = $specs->mapWithKeys(function ($spec) {
            return [$spec->line_id => $spec->value];
        })->toArray();
        $result = [];
        foreach ($lineIds as $id) {
            $result[$id] = $lotSizes[$id] ?? 11000;
        }

        return $result;
    }

    function getRollChangeTime($productId, $lineId)
    {
        // Truy vấn để lấy giá trị thời gian lên xuống cuộn từ bảng spec theo slug 'thoi-gian-len-xuong-cuon'

        $rollChangeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'thoi-gian-len-xuong-cuon')
            ->first();

        return $rollChangeSpec ? $rollChangeSpec->value : 0;
    }

    public static function getEfficiency($productId, $lineId)
    {
        // Truy vấn để lấy giá trị năng suất từ bảng spec theo slug 'nang-suat'
        $efficiencySpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'nang-suat-an-dinhgio')
            ->first();

        return $efficiencySpec ? $efficiencySpec->value : 0;
    }

    function getEfficiencys($productId, array $lineIds)
    {
        $specs = Spec::whereIn('line_id', $lineIds)
            ->where('product_id', $productId)
            ->where('slug', 'nang-suat-an-dinhgio')
            ->get();

        $efficiencies = $specs->mapWithKeys(function ($spec) {
            return [$spec->line_id => $spec->value];
        })->toArray();

        $result = [];
        foreach ($lineIds as $id) {
            $result[$id] = $efficiencies[$id] ?? 0;
        }

        return $result;
    }

    function getBottleneckStage($productId)
    {
        // Truy vấn để lấy công đoạn bottleneck từ bảng spec theo slug 'bottleneck'
        $bottleneckSpec = Spec::where('product_id', $productId)
            ->where('slug', 'nang-suat-an-dinhgio')
            ->orderByRaw('CAST(value AS UNSIGNED) ASC')
            ->first();

        return $bottleneckSpec;
    }

    function getRollsPerTransport($productId, $lineId)
    {
        // Truy vấn để lấy số lượng cuộn một lần vận chuyển từ bảng spec theo slug 'so-luong-cuon-1-lan-van-chuyen'
        $rollsPerTransportSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'so-luong-cuon-1-lan-van-chuyen-cuon')
            ->first();

        return $rollsPerTransportSpec ? $rollsPerTransportSpec->value : 0;
    }

    public static function getSetupTime($productId, $lineId)
    {
        // Truy vấn để lấy giá trị thời gian vào hàng từ bảng spec theo slug 'vao-hang-setup-may'
        $setupTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'vao-hang-setup-may')
            ->first();

        return $setupTimeSpec ? $setupTimeSpec->value : 0; // Nếu không tìm thấy, trả về 0
    }

    function getMachineReady($lineId, $numMachines, $productId, $machine_available_list, $startTime)
    {
        // Lấy thứ tự ưu tiên của máy
        $machinePriorityOrder = MachinePriorityOrder::where('product_id', $productId)
            ->where('line_id', $lineId)
            ->orderBy('priority')
            ->pluck('priority', 'machine_id')
            ->toArray();
        if (count($machinePriorityOrder) == 0) {
            $machineList = Machine::where('line_id', $lineId)->get()->sortBy('code')->values();
            foreach ($machineList as $key => $value) {
                $machinePriorityOrder[$value->code]['priority'] = $key + 1;
            }
        }
        // Lấy danh sách mã máy từ thứ tự ưu tiên
        $machineCodes = array_keys($machinePriorityOrder);

        // Truy vấn danh sách máy dựa trên line_id và mã máy
        if ($lineId == 29) {
            $machines = Machine::select('code', 'available_at', 'line_id')
                ->where('line_id', $lineId)
                ->get();
        } else {
            $machines = Machine::select('code', 'available_at', 'line_id')
                ->where('line_id', $lineId)
                ->whereIn('code', $machineCodes)
                ->get();
        }

        // Cập nhật thời gian sẵn sàng và ưu tiên cho từng máy
        foreach ($machines as $machine) {
            if (isset($machine_available_list[$machine->code])) {
                $machine->available_at = $machine_available_list[$machine->code];
            }
            $machine->priority = $machinePriorityOrder[$machine->code] ?? PHP_INT_MAX;
        }

        // Phân chia máy thành hai nhóm: trước và sau thời gian bắt đầu
        [$beforeStart, $afterStart] = $machines->partition(function ($machine) use ($startTime) {
            $readyTime = Carbon::parse($machine->available_at);
            return $readyTime->lessThanOrEqualTo($startTime) || is_null($machine->available_at);
        });
        // Sắp xếp nhóm trước thời gian bắt đầu theo ưu tiên
        if ($beforeStart->isNotEmpty()) {
            $beforeStart = $beforeStart->sortBy('priority');
        }

        // Sắp xếp nhóm sau thời gian bắt đầu theo thời gian sẵn sàng và ưu tiên
        if ($afterStart->isNotEmpty()) {
            $afterStart = $afterStart->sort(function ($a, $b) {
                $readyTimeA = Carbon::parse($a->available_at);
                $readyTimeB = Carbon::parse($b->available_at);

                if ($readyTimeA->equalTo($readyTimeB)) {
                    return $a->priority - $b->priority;
                }
                return $readyTimeA->lessThan($readyTimeB) ? -1 : 1;
            });
        }
        return $beforeStart->concat($afterStart)->take($numMachines)->values();
    }

    public static function getMachineProductionShifts($machineId, $date): Collection
    {
        $cacheKey = "machine_{$machineId}_production_shifts_{$date}";

        return Cache::remember($cacheKey, 60, function () use ($machineId, $date) {
            // Lấy tất cả các shift_id của máy trong ngày
            $shiftIds = DB::table('machine_shift')
                ->where('machine_id', $machineId)
                ->where('date', $date)
                ->pluck('shift_id');

            if ($shiftIds->isEmpty()) {
                return collect();
            }

            // Lấy tất cả các shift_breaks có type_break là 'Sản xuất' cho các shift_id
            return DB::table('shift_breaks')
                ->whereIn('shift_id', $shiftIds)
                ->select('shift_id', 'start_time', 'end_time', 'duration_minutes', 'type_break')
                ->orderBy('id')
                ->get();
        });
    }

    function getMachineLoadFactors($machineId, $date, $machineLoadFactors = [])
    {
        $machine_load_factor = collect(array_values($machineLoadFactors))->where('machine_code', $machineId)
            ->where('date', $date)
            ->first();
        return $machine_load_factor ? $machine_load_factor['work_hours'] : 0;
    }

    public function adjustTimeWithinShift($startTime, $duration, $machineId, $shiftPreparationTime)
    {
        // Chuyển đổi $startTime sang đối tượng Carbon với múi giờ 'Asia/Bangkok' nếu chưa phải đối tượng Carbon
        if (!$startTime instanceof Carbon) {
            $startTime = Carbon::parse($startTime, 'Asia/Bangkok');
        } else {
            $startTime->setTimezone('Asia/Bangkok');
        }

        $currentTime = $startTime->copy();
        $remainingDuration = $duration;
        $maxDays = 30;
        $daysProcessed = 0;

        while ($remainingDuration > 0 && $daysProcessed < $maxDays) {
            $currentDate = $currentTime->copy()->startOfDay();
            $dateString = $currentDate->toDateString();
            $productionShifts = $this->getMachineProductionShifts($machineId, $dateString);

            if ($productionShifts->isEmpty()) {
                $currentTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }

            foreach ($productionShifts as $shift) {
                $shiftStart = Carbon::parse("{$dateString} {$shift->start_time}", 'Asia/Bangkok');
                $shiftEnd = Carbon::parse("{$dateString} {$shift->end_time}", 'Asia/Bangkok');

                if ($shiftStart->hour < 7 && $startTime->hour >= 7) {
                    $shiftStart->addDay();
                }

                if ($shiftStart->hour >= 7 && $startTime->hour < 7) {
                    $shiftStart->subDay();
                }

                if ($shiftEnd->hour < 7 && $startTime->hour >= 7) {
                    $shiftEnd->addDay();
                }
                if ($currentTime->lessThan($shiftStart) && $currentTime->day == $shiftStart->day) {
                    if (!$currentTime->equalTo($startTime)) {
                        $currentTime->addMinutes($shiftPreparationTime);
                    }
                    $currentTime = $shiftStart->copy();
                }

                if ($currentTime->between($shiftStart, $shiftEnd) || $currentTime->equalTo($shiftStart)) {
                    $availableTime = $shiftEnd->diffInMinutes($currentTime);

                    if ($availableTime >= $remainingDuration) {
                        $endTime = $currentTime->copy()->addMinutes($remainingDuration);
                        return [$startTime, $endTime];
                    } else {
                        $currentTime = $shiftEnd->copy();
                        $remainingDuration -= $availableTime;
                    }
                } elseif ($currentTime->greaterThanOrEqualTo($shiftEnd)) {
                    continue;
                }
            }

            $currentTime->addDay()->startOfDay();
            $daysProcessed++;
        }
        return [$startTime, $currentTime];
    }

    function getShiftPreparationTime($productId, $lineId)
    {
        $preparationTimeSpec = Spec::where('line_id', $lineId)
            ->where('product_id', $productId)
            ->where('slug', 'chuan-bidau-ca')
            ->first();

        return $preparationTimeSpec ? $preparationTimeSpec->value : 0;
    }

    function getNumberMachine($orderId)
    {
        $numberMachineOrders = NumberMachineOrder::where('product_order_id', $orderId)->get();
        return $numberMachineOrders->pluck('number_machine', 'line_id');
    }

    private function getAvailableMaterialsForProduct($product)
    {
        $materials = $product->materials;
        foreach ($materials as $key => $material) {
            if ($key == 0) {
                return $material->id;
            }
        }
        throw new Exception("Không có nguyên vật liệu khả dụng cho sản phẩm {$product->name}.");
    }

    private function groupOrdersByAvailableMaterial($orders)
    {
        $groupedOrders = [];

        foreach ($orders as $order) {
            try {
                $materialId = $this->getAvailableMaterialsForProduct($order->product);
                if (!isset($groupedOrders[$materialId])) {
                    $groupedOrders[$materialId] = [];
                }

                $groupedOrders[$materialId][] = $order;
            } catch (Exception $e) {
                continue;
            }
        }

        return $groupedOrders;
    }

    public function generateProductionPlan(Request $request)
    {
        $orderIds = $request->order_id;
        $data = [];
        $machine_available_list = [];
        $machine_load_factors = MachineLoadFactor::where('date', '>=', date('Y-m-d'))->get()->mapWithKeys(function ($machine) {
            return [$machine->machine_code . "_" . $machine->date . "_" . $machine->shift_id => $machine];
        })->toArray();
        $orders = ProductOrder::with(['product.materials', 'customer'])
            ->whereIn('id', $orderIds)
            ->get();
        $groupedOrders = $this->groupOrdersByAvailableMaterial($orders);
        $sortedGroups = [];
        foreach ($groupedOrders as $materialId => $groupOrders) {
            $sortedOrders = collect($groupOrders)->sort(function ($a, $b) {
                if ($a->delivery_date != $b->delivery_date) {
                    return $a->delivery_date < $b->delivery_date ? -1 : 1;
                }
                return 0;
            });

            $sortedGroups[$materialId] = $sortedOrders;
        }
        $sortedMaterialGroups = collect($sortedGroups)->sort(function ($a, $b) {
            $earliestDeadlineA = $a->min('delivery_date');
            $earliestDeadlineB = $b->min('delivery_date');
            return $earliestDeadlineA <=> $earliestDeadlineB;
        });

        $prioritizedOrders = $sortedMaterialGroups->flatten(1);
        $sortedByProductId = collect($prioritizedOrders)->groupBy('product_id')->flatten(1);
        foreach ($sortedByProductId as $index => $order) {
            try {
                $result = $this->processProductionPlan($order, $index, $machine_available_list, $machine_load_factors);
                if ($result) {
                    $data[] = $result;
                }
            } catch (\Throwable $th) {
                throw $th;
                return $this->failure('', $th->getMessage());
            }
        }
        if (count($data) <= 0) {
            return $this->failure('', 'Không có kế hoạch nào được tạo');
        }
        return $this->success($data);
    }

    function calculateEndTime1($startTime, $taskTime, $lotSize, $rollChangeTime, $numLots, $setupTime)
    {
        $workdayStartHour = 7 * 60 + 30; // 7:30 sáng (phút từ 0h)
        $workdayEndHour = 19 * 60;       // 19:00 tối (phút từ 0h)
        $workdayDuration = $workdayEndHour - $workdayStartHour; // Thời gian làm việc mỗi ngày (phút)

        // Tổng thời gian công việc
        $totalMinutes = ((($taskTime * $lotSize) + $rollChangeTime) * $numLots) + $setupTime;

        // Chuyển thời gian bắt đầu sang phút từ 0h
        $currentMinutes = $startTime->hour * 60 + $startTime->minute;

        // Nếu thời gian bắt đầu trước giờ làm việc, điều chỉnh đến 7:30
        if ($currentMinutes < $workdayStartHour) {
            $currentMinutes = $workdayStartHour;
        }

        // Tính toán
        while ($totalMinutes > 0) {
            // Tính số phút còn lại trong ngày làm việc hiện tại
            $remainingMinutesToday = $workdayEndHour - $currentMinutes;

            if ($totalMinutes <= $remainingMinutesToday) {
                // Nếu thời gian đủ để hoàn thành công việc trong ngày
                $currentMinutes += $totalMinutes;
                $totalMinutes = 0;
            } else {
                // Nếu không, tiêu thụ hết thời gian trong ngày và chuyển sang ngày kế tiếp
                $totalMinutes -= $remainingMinutesToday;
                $currentMinutes = $workdayStartHour; // Đặt lại thời gian bắt đầu ngày tiếp theo
            }
        }

        // Chuyển phút từ 0h thành giờ và phút thực tế
        $daysAdded = intdiv($currentMinutes, 24 * 60); // Số ngày vượt quá
        $finalMinutes = $currentMinutes % (24 * 60);  // Phút còn lại trong ngày
        $hour = intdiv($finalMinutes, 60);
        $minute = $finalMinutes % 60;

        // Trả về đối tượng Carbon với ngày giờ được tính toán
        return $startTime->copy()->addDays($daysAdded)->setTime($hour, $minute);
    }

    function getSpecByKey($productionSteps, $productId, $materialId, $key)
    {
        $lineIdFirst = end($productionSteps);
        // Khởi tạo mảng kết quả
        $specData = [];
        // Nếu công đoạn đầu tiên là gấp dán, lấy từ $material
        if ($lineIdFirst == 24) {
            $specData = Spec::whereIn('product_id', [$materialId, $productId])
                ->where('line_id', 24)
                ->where('slug', $key)
                ->pluck('value', 'line_id')
                ->all();
            // Loại bỏ line_id = 24 khỏi danh sách $productionSteps
            $productionSteps = array_diff($productionSteps, [24]);
        }
        // Lấy dữ liệu còn lại từ $product
        $specForOthers = Spec::where('product_id', $productId)
            ->whereIn('line_id', $productionSteps)
            ->where('slug', $key)
            ->pluck('value', 'line_id')
            ->all();
        // Gộp kết quả từ hai truy vấn
        $specData = ($specData + $specForOthers);
        return $specData;
    }

    function getMachineReadyV2($lineId, $numMachines, $productId, $machine_available_list, $startTime, $machine_load_factors)
    {
        //Lấy máy đã quá hệ số tải
        $overloadedMachines = collect($machine_load_factors)
            ->filter(function (array $machine) use ($startTime) {
                return ($machine['date'] === $startTime->toDateString()) && ($machine['fixed_hours'] - $machine['work_hours'] <= 0.5);
            })->values()->pluck('machine_code')->toArray();
        // Lấy thứ tự ưu tiên của máy
        $machinePriorityOrder = MachinePriorityOrder::where('product_id', $productId)
            ->where('line_id', $lineId)
            ->orderBy('priority')
            ->pluck('priority', 'machine_id')
            ->toArray();
        if (count($machinePriorityOrder) == 0) {
            $machineList = Machine::where('line_id', $lineId)->get()->sortBy('code')->values();
            foreach ($machineList as $key => $value) {
                $machinePriorityOrder[$value->code]['priority'] = $key + 1;
            }
        }
        // Lấy danh sách mã máy từ thứ tự ưu tiên
        $machineCodes = array_keys($machinePriorityOrder);

        // Truy vấn danh sách máy dựa trên line_id và mã máy
        if ($lineId == 29) {
            $machines = Machine::select('code', 'available_at', 'line_id')
                ->where('line_id', $lineId)
                ->get();
        } else {
            $machines = Machine::select('code', 'available_at', 'line_id')
                ->where('line_id', $lineId)
                ->whereIn('code', $machineCodes)
                ->whereNotIn('code', $overloadedMachines)
                ->get();
        }

        // Cập nhật thời gian sẵn sàng và ưu tiên cho từng máy
        foreach ($machines as $machine) {
            if (isset($machine_available_list[$machine->code])) {
                $machine->available_at = $machine_available_list[$machine->code];
            }
            $machine->priority = $machinePriorityOrder[$machine->code] ?? PHP_INT_MAX;
        }

        // Phân chia máy thành hai nhóm: trước và sau thời gian bắt đầu
        [$beforeStart, $afterStart] = $machines->partition(function ($machine) use ($startTime) {
            $readyTime = Carbon::parse($machine->available_at);
            return $readyTime->lessThanOrEqualTo($startTime) || is_null($machine->available_at);
        });
        // Sắp xếp nhóm trước thời gian bắt đầu theo ưu tiên
        if ($beforeStart->isNotEmpty()) {
            $beforeStart = $beforeStart->sortBy('priority');
        }

        // Sắp xếp nhóm sau thời gian bắt đầu theo thời gian sẵn sàng và ưu tiên
        if ($afterStart->isNotEmpty()) {
            $afterStart = $afterStart->sort(function ($a, $b) {
                $readyTimeA = Carbon::parse($a->available_at);
                $readyTimeB = Carbon::parse($b->available_at);

                if ($readyTimeA->equalTo($readyTimeB)) {
                    return $a->priority - $b->priority;
                }
                return $readyTimeA->lessThan($readyTimeB) ? -1 : 1;
            });
        }
        return $beforeStart->concat($afterStart)->take($numMachines)->values();
    }

    public function processProductionPlan($order, $orderIndex = 0, &$machine_available_list = [])
    {
        // 1. Kiểm tra điều kiện đầu vào
        if (!$order->sl_giao_sx) {
            throw new Exception("Không có số lượng giao sản xuất", 1);
        }

        // 2. Khởi tạo các biến cơ bản
        $orderId      = $order->id;
        $productId    = $order->product_id;
        $inventory    = Inventory::where('product_id', $productId)->first();

        // Dùng max(0, ...) để tránh trường hợp âm.
        $initialQuantity = max(0, ($order->sl_giao_sx - ($inventory->sl_ton ?? 0)));

        // 3. Lấy danh sách công đoạn & bom
        $productionSteps       = $this->getProductionSteps($productId);
        $lineProductionArray   = $productionSteps->pluck('line_id')->toArray();
        $materialId            = $productId;
        // Nếu công đoạn cuối là line_id = 24 và tìm thấy bom
        if (end($lineProductionArray) == 24) {
            $bom = Bom::where('product_id', $productId)
                ->whereRaw('priority REGEXP "^[0-9]+$"')
                ->first();
            if ($bom) {
                $materialId = $bom->material_id;
            }
        }

        // 4. Lấy các thông số cần thiết
        $stepQuantities       = [];
        $productionTimes      = [];
        $numberMachineByStep  = $this->getNumberMachine($orderId);
        $inputWaste           = $this->calculateProductionWastage($productId);
        $lineProductionWaste  = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'hao-phi-san-xuat-cac-cong-doan');
        $lineInputWaste       = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'hao-phi-vao-hang-cac-cong-doan');
        $lineInventory        = LineInventories::where('product_id', $productId)->pluck('quantity', 'line_id');
        $efficiencies         = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'nang-suat-an-dinhgio');

        // 5. Tính toán số lượng và thời gian sản xuất cho từng công đoạn
        $line_must_run = [];
        foreach ($productionSteps as $step) {
            $lineId            = $step->line_id;
            $lineName          = $step->line->name;
            $calculatedQuantity = $this->modifiedCalculateProductionOutput(
                $lineId,
                $initialQuantity,
                $lineProductionWaste,
                $lineInputWaste,
                $lineInventory
            );

            // Lưu lại công đoạn nếu có số lượng > 0
            if ($calculatedQuantity !== 0) {
                $line_must_run[] = $lineId;
            }

            // Lưu kết quả tính được
            $stepQuantities[$lineId] = $calculatedQuantity;

            // Tính thời gian (giờ) nếu có năng suất
            $efficiencySpec = $efficiencies[$lineId] ?? throw new Exception("Không tìm thấy năng suất cho sản phẩm $productId tại công đoạn $lineName", 1);
            if ($efficiencySpec > 0) {
                // round(..., 2) làm tròn 2 chữ số thập phân
                $productionTimes[$lineId] = round($calculatedQuantity / $efficiencySpec, 2);
            }

            // Số lượng đầu ra của công đoạn này = đầu vào cho công đoạn kế
            $initialQuantity = $calculatedQuantity;
        }

        // 6. Lấy thứ tự công đoạn sắp xếp (ordered)
        $orderedSteps = $this->getOrderedProductionSteps($productId)
            ->filter(fn($value) => in_array($value->line_id, $line_must_run))
            ->values();
        // Nếu không có công đoạn nào phải chạy => không cần tính tiếp
        if ($orderedSteps->count() <= 0) {
            return null;
        }
        foreach ($orderedSteps as $key => $step) {
            if ($key == 0 && $step->line_id == 24) {
                $step->product_id = $materialId;
            } else {
                $step->product_id = $productId;
            }
        }
        // 7. Chuẩn bị các biến để tính lô/lots
        $stepEndTimes      = [];
        $lots              = [];
        $plans             = [];
        $lot_plans         = [];
        $machine_input     = [];
        $isExceedDeliveryTime = false;
        $machine_in_line   = [];

        // Tạo mã lô sản xuất (LOSX)
        $losx_id  = Losx::generateUniqueIdPreview($orderIndex);
        $lo_sx    = Losx::where('product_order_id', $orderId)->first();
        if ($lo_sx) {
            $losx_id = $lo_sx->id;
        }

        // Lưu lại input để cập nhật/khởi tạo Losx
        $losx_input = [
            'product_order_id' => $orderId,
            'id'               => $losx_id,
        ];

        // 8. Lấy các thông số về lô
        $lineLotSize          = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'so-luong');
        $rollChangeTimes      = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'thoi-gian-len-xuong-cuon');
        $rollsPerTransports   = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'so-luong-cuon-1-lan-van-chuyen-cuon');
        $lineSetupTime        = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'vao-hang-setup-may');
        $preparationTimeSpecs = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'chuan-bidau-ca');
        $transportTimeSpecs   = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau');

        // 9. Vòng lặp các công đoạn đã sắp xếp
        $startTime = null;
        foreach ($orderedSteps as $index => $step) {
            $productId = $step->product_id;
            $lineId   = $step->line_id;
            $quantity = $stepQuantities[$lineId];

            // Mặc định 1 lot = 11000 (nếu lấy từ spec thì dùng spec, nếu không có thì gán mặc định)
            $lotSize  = $lineLotSize[$lineId] ?? 11000;

            // Tạo mảng lots cho lineId này
            if (!isset($lots[$lineId])) {
                $lots[$lineId] = [];
            }

            $product = Product::find($productId);
            if (!$product) {
                throw new Exception("Không tìm thấy mã sản phẩm " . $productId, 1);
            }

            $rollChangeTime = $rollChangeTimes[$lineId] ?? throw new Exception("Không tìm thấy thời gian lên xuống cuộn cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $efficiency     = $efficiencies[$lineId] ?? throw new Exception("Không tìm thấy năng suất cho sản phẩm $productId tại công đoạn " . $step->line->name, 1);
            // taskTime = số phút để làm xong 1 sản phẩm (minute/product)
            $taskTime       = $efficiency > 0 ? 60 / $efficiency : 0;

            // Số lượng cuộn 1 lần vận chuyển
            $rollsPerTransport = $rollsPerTransports[$lineId] ?? throw new Exception("Không tìm thấy số lượng cuộn 1 lần vận chuyển cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $setupTime         = $lineSetupTime[$lineId] ?? throw new Exception("Không tìm thấy thời gian vào hàng setup máy cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $shiftPrepTime     = $preparationTimeSpecs[$lineId] ?? throw new Exception("Không tìm thấy thời gian chuẩn bị ca cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $transportTime     = $transportTimeSpecs[$lineId] ?? throw new Exception("Không tìm thấy thời gian vận chuyển cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);

            // 9.1. Tính $startTime cho công đoạn đầu tiên hay tiếp theo
            if (!isset($startTime)) {
                // Công đoạn đầu tiên => bắt đầu từ 7h30 sáng ngày hôm sau
                $startTime = Carbon::now()->addDay()->setTime(7, 30, 0);
            } else {
                // Điều chỉnh rollsPerTransport nếu cần
                $rollsPerTransport = $this->adjustRollsPerTransport(
                    $rollsPerTransport,
                    $quantity,
                    $lotSize,
                    $index,
                    $orderedSteps,
                    $inputWaste
                );

                // Lấy endTime của công đoạn trước => + transportTime
                //   (Cần cẩn thận kiểm tra index - 1, máy, v.v...)
                try {
                    $prevLineId = $orderedSteps[$index - 1]->line_id ?? null;
                    if (
                        $prevLineId
                        && isset($machine_in_line[$prevLineId])
                        && $machine_in_line[$prevLineId] > 0
                    ) {
                        $prevMachineCount = $machine_in_line[$prevLineId];
                        $transportIndex   = ($rollsPerTransport / $prevMachineCount) - 1;

                        // Kiểm tra phần tử lots cũ
                        if (
                            isset($lots[$prevLineId][$prevMachineCount - 1]) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1][$transportIndex]) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1][$transportIndex]['endTime']) &&
                            !isset($lineInventory[$prevLineId])
                        ) {
                            $prevEndTime = $lots[$prevLineId][$prevMachineCount - 1][$transportIndex]['endTime'];
                            $startTime   = $prevEndTime->copy()->addMinutes($transportTime);
                        }
                    }
                } catch (\Throwable $th) {
                    throw $th;
                }
            }

            // 9.2. Lấy máy có sẵn
            $numMachines = $numberMachineByStep[$lineId] ?? 0;
            $machines    = $this->getMachineReady($lineId, $numMachines, $productId, $machine_available_list, $startTime);

            $numMachines = count($machines);
            $machine_in_line[$lineId] = $numMachines;

            // Mỗi máy xử lý 1 phần quantity
            $quantityPerMachine = $numMachines > 0 ? ceil($quantity / $numMachines) : $quantity;
            $numLots            = ceil($quantityPerMachine / $lotSize);
            $lotIndexOffset     = 0;

            // 9.3. Vòng lặp từng máy
            foreach ($machines as $machineIndex => $machine) {
                $machineReadyTime = Carbon::parse($machine->available_at, 'Asia/Bangkok');
                if (!$startTime->greaterThan($machineReadyTime)) {
                    $startTime = $machineReadyTime;
                }
                // Ước tính endTime cho toàn bộ lot
                $endTime = $this->calculateEndTime1($startTime, $taskTime, $lotSize, $rollChangeTime, $numLots, $setupTime);
                $stepEndTimes[$lineId] = $endTime;

                $planInput = [
                    'product_order_id'  => $orderId,
                    'ngay_dat_hang'     => $order->order_date,
                    'ngay_sx'           => $startTime,
                    'ngay_giao_hang'    => $order->delivery_date,
                    'line_id'           => $lineId,
                    'cong_doan_sx'      => $step->line->name,
                    'ca_sx'             => 1,
                    'delivery_date'     => $order->delivery_date ? date('Y-m-d', strtotime($order->delivery_date)) : null,
                    'machine_id'        => $machine->code,
                    'product_id'        => $productId,
                    'ten_san_pham'      => $product->name ?? "",
                    'khach_hang'        => $order->customer->name ?? "",
                    'lo_sx'             => $losx_id,
                    'thu_tu_uu_tien'    => 1,
                    'nhan_luc'          => 1,
                    'tong_tg_thuc_hien' => ($quantity * $taskTime)
                        + ($rollChangeTime * $numLots)
                        + $setupTime,
                    'thoi_gian_bat_dau' => $startTime,
                    'thoi_gian_ket_thuc' => $endTime,
                    'sl_giao_sx'        => $quantityPerMachine,
                ];

                // 9.4. Xây dựng danh sách các lots chi tiết
                $lot_in_plan = [];
                // Đếm số lot đã có trong InfoCongDoan
                $countLot = InfoCongDoan::query()->where([
                    'lo_sx'   => $losx_id,
                    'line_id' => $lineId
                ])->count() + $lotIndexOffset;

                for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
                    $countLot++;
                    // Tạo lot_id (vd: LOSX123.L.0001)
                    $lotId = $losx_id . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT);

                    // Tính thời gian start - end cho lot
                    $lotStartTime = ($lotIndex == 1)
                        ? $startTime
                        : $lots[$lineId][$machineIndex][$lotIndex - 2]['endTime'];

                    // Ở đây logic cũ có chỗ chia “quantity % lotSize” cho lot đầu tiên:
                    // - T tuỳ chỉnh lại thành quantityPerLot = $lotSize, trừ trường hợp lot đầu tiên
                    //   trên máy đầu tiên, ta có thể “tận dụng” remainder. 
                    $quantityPerLot = (
                        $lotIndex == 1 && $machineIndex == 0
                    )
                        ? ($quantity % $lotSize ?: $lotSize)
                        : $lotSize;

                    // Điều chỉnh thời gian theo ca
                    list($lotStartTime, $lotEndTime) = $this->adjustTimeWithinShift(
                        $lotStartTime,
                        ($taskTime * $quantityPerLot) + $rollChangeTime,
                        $machine->code,
                        $shiftPrepTime
                    );

                    // Kiểm tra vượt deadline
                    if ($order->delivery_date && $lotStartTime->greaterThan(Carbon::parse($order->delivery_date))) {
                        $isExceedDeliveryTime = true;
                    }

                    // Tạo dữ liệu lot
                    $lotPlanInput = [
                        'lot_id'           => $lotId,
                        'lo_sx'            => $losx_id,
                        'line_id'          => $lineId,
                        'product_id'       => $productId,
                        'machine_code'     => $machine->code,
                        'start_time'       => $lotStartTime,
                        'end_time'         => $lotEndTime,
                        'quantity'         => $quantityPerLot,
                        'lot_size'         => $quantityPerLot,
                        'product_order_id' => $orderId,
                        'customer_id'      => $order->customer_id,
                        'sl_giao_sx'       => $quantityPerLot,
                        'ca_sx'            => 1,
                        'cong_doan_sx'     => $step->line->name,
                        'machine_id'       => $machine->code,
                        'ten_san_pham'     => $product->name ?? "",
                        'khach_hang'       => $order->customer_id,
                        'thoi_gian_bat_dau' => $lotStartTime,
                        'thoi_gian_ket_thuc' => $lotEndTime,
                        'is_exceed_time'   => $isExceedDeliveryTime,
                    ];

                    // Thêm lot vào danh sách
                    $lot_plans[]  = $lotPlanInput;
                    $lot_in_plan[] = $lotPlanInput;

                    // Lưu vào lots chung
                    $lots[$lineId][$machineIndex][] = [
                        'lot_id'    => $lotId,
                        'quantity'  => $quantityPerLot,
                        'startTime' => $lotStartTime,
                        'endTime'   => $lotEndTime,
                    ];
                }

                // Cập nhật plan
                $planInput['lots']            = $lot_in_plan;
                $planInput['is_exceed_time']  = $isExceedDeliveryTime;

                // Cập nhật thời gian kết thúc cho plan
                if (!empty($lots[$lineId][$machineIndex])) {
                    $stepEndTimes[$lineId]          = end($lots[$lineId][$machineIndex])['endTime'];
                    $planInput['thoi_gian_ket_thuc'] = $stepEndTimes[$lineId];
                }

                // Lưu plan
                $plans[] = $planInput;
                $lotIndexOffset += $numLots;

                // Cập nhật thời gian available của máy
                $machine_input[] = [
                    'machine_code' => $machine->code,
                    'available_at' => $stepEndTimes[$lineId],
                ];
                if (
                    !isset($machine_available_list[$machine->code]) ||
                    $stepEndTimes[$lineId]->greaterThan($machine_available_list[$machine->code])
                ) {
                    $machine_available_list[$machine->code] = $stepEndTimes[$lineId]->format('Y-m-d H:i:s');
                }
            }
        }

        // 10. Tổng hợp dữ liệu lô sản xuất (LOSX)
        $losx_input['lo_sx']           = $losx_id;
        $losx_input['sl_giao_sx']      = $order->sl_giao_sx;
        $losx_input['product_id']      = $productId;
        $losx_input['product_name']    = $product->name ?? "";
        $losx_input['thoi_gian_bat_dau'] = !empty($plans) ? (reset($plans)['thoi_gian_bat_dau'] ?? null) : null;
        $losx_input['thoi_gian_ket_thuc'] = !empty($plans) ? (end($plans)['thoi_gian_ket_thuc'] ?? null) : null;
        $losx_input['khach_hang']      = $order->customer->name ?? "";
        $losx_input['delivery_date']   = $order->delivery_date ? date('d/m/Y', strtotime($order->delivery_date)) : null;
        $losx_input['plans']           = $plans;
        $losx_input['is_exceed_time']  = $isExceedDeliveryTime;

        // 11. Trả về dữ liệu
        return [
            'lots'     => $lot_plans,
            'plans'    => $plans,
            'machines' => $machine_input,
            'lo_sx'    => $losx_input,
        ];
    }

    /**
     * Ví dụ hàm tinh chỉnh số lượng cuộn vận chuyển giữa 2 công đoạn:
     */
    private function adjustRollsPerTransport(
        $rollsPerTransport,
        $quantity,
        $lotSize,
        $index,
        $orderedSteps,
        $inputWaste
    ) {
        // Nếu 0 hoặc nếu ceil($quantity / $lotSize) < $rollsPerTransport => điều chỉnh
        if ($rollsPerTransport === 0 || ceil($quantity / $lotSize) < $rollsPerTransport) {
            // line hiện tại
            $currentLineId = $orderedSteps[$index]->line_id;
            // Lấy hao phí đầu vào (nếu có)
            $waste = $inputWaste[$currentLineId] ?? 0;
            // Tính lại
            $rollsPerTransport = ceil($quantity / ($lotSize + $waste));
        }
        return $rollsPerTransport;
    }
    public function processProductionPlanV1($order, $orderIndex = 0, &$machine_available_list = [])
    {
        if (!$order->sl_giao_sx) {
            throw new Exception("Không có số lượng giao sản xuất", 1);
        }
        $stepQuantities = [];
        $productionTimes = [];
        $line_must_run = [];
        $productId = $order->product_id;
        $workingHoursPerDay = 8.0;
        $finishTime = 0;

        // Tính số lượng cần sản xuất trừ tồn
        $inventory = Inventory::where('product_id', $productId)->first();
        $quantity = $inventory ? $order->sl_giao_sx - $inventory->sl_ton : $order->sl_giao_sx;

        $productionSteps = $this->getProductionSteps($productId);
        $bottleneckSpec = $this->getBottleneckStage($productId);
        $taskTime = 1 / $bottleneckSpec->value;
        $lineIDs = $productionSteps->pluck('line_id')->toArray();
        $lotSizes = $this->getLotSizes($productId, $lineIDs);
        $efficiencySpecs = $this->getEfficiencys($productId, $lineIDs);
        return $lotSizes;
        foreach ($productionSteps as $step) {
            $calculatedQuantity = $this->calculateProductionOutput($productId, $step->line_id, $quantity);
            $lotsize = $lotSizes[$step->line_id];
            if ($calculatedQuantity !== 0) {
                $line_must_run[] = $step->line_id;
            };
            $stepQuantities[$step->line_id] = $calculatedQuantity;
            $efficiencySpec = $this->getEfficiency($productId, $step->line_id);
            if ($efficiencySpec > 0) {
                $productionTimes[$step->line_id] = round($calculatedQuantity / $efficiencySpec, 2);
            }
            $quantity = $calculatedQuantity;
            if ($step->line_id != $bottleneckSpec->line_id) {
                $finishTime += round($lotsize / $efficiencySpec, 2);
            } else {
                $bottleneckTime = round($calculatedQuantity / $efficiencySpec, 2);
            }
        }

        $orderedSteps = $productionSteps->reverse()->values();
        $orderedSteps = $orderedSteps->filter(function ($value) use ($line_must_run) {
            return in_array($value->line_id, $line_must_run);
        })->values();
        $totalProductionTime = $finishTime + $bottleneckTime;
        $startDate = Carbon::now()->addDay()->setTime(7, 30, 0);
        $endDate = Carbon::parse($order->delivery_date)->setTime(12, 00, 00);
        $requiredWorkingDays = ceil($totalProductionTime / $workingHoursPerDay);
        $estimatedEndDate = $startDate->copy()->addDays($requiredWorkingDays - 1);
        if ($estimatedEndDate->gt($endDate)) {
            $canMeetDeadline = false;
        } else {
            $canMeetDeadline = true;
        }
        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'estimated_end_date' => $estimatedEndDate,
        ];
        return [
            'lots' => $lot_plans,
            'plans' => $plans,
            'machines' => $machine_input,
            'lo_sx' => $losx_input,
        ];
    }

    public function processProductionPlanV2($order, $orderIndex = 0, &$machine_available_list = [], &$machine_load_factors = [])
    {
        // 1. Kiểm tra điều kiện đầu vào
        if (!$order->sl_giao_sx) {
            throw new Exception("Không có số lượng giao sản xuất", 1);
        }

        // 2. Khởi tạo các biến cơ bản
        $orderId      = $order->id;
        $productId    = $order->product_id;
        $inventory    = Inventory::where('product_id', $productId)->first();

        // Dùng max(0, ...) để tránh trường hợp âm.
        $initialQuantity = max(0, ($order->sl_giao_sx - ($inventory->sl_ton ?? 0)));

        // 3. Lấy danh sách công đoạn & bom
        $productionSteps       = $this->getProductionSteps($productId);
        $lineProductionArray   = $productionSteps->pluck('line_id')->toArray();
        $materialId            = $productId;
        // Nếu công đoạn cuối là line_id = 24 và tìm thấy bom
        if (end($lineProductionArray) == 24) {
            $bom = Bom::where('product_id', $productId)
                ->whereRaw('priority REGEXP "^[0-9]+$"')
                ->first();
            if ($bom) {
                $materialId = $bom->material_id;
            }
        }

        // 4. Lấy các thông số cần thiết
        $stepQuantities       = [];
        $productionTimes      = [];
        $numberMachineByStep  = $this->getNumberMachine($orderId);
        $inputWaste           = $this->calculateProductionWastage($productId);
        $lineProductionWaste  = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'hao-phi-san-xuat-cac-cong-doan');
        $lineInputWaste       = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'hao-phi-vao-hang-cac-cong-doan');
        $lineInventory        = LineInventories::where('product_id', $productId)->pluck('quantity', 'line_id');
        $efficiencies         = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'nang-suat-an-dinhgio');

        // 5. Tính toán số lượng và thời gian sản xuất cho từng công đoạn
        $line_must_run = [];
        foreach ($productionSteps as $step) {
            $lineId            = $step->line_id;
            $lineName          = $step->line->name;
            $calculatedQuantity = $this->modifiedCalculateProductionOutput(
                $lineId,
                $initialQuantity,
                $lineProductionWaste,
                $lineInputWaste,
                $lineInventory
            );

            // Lưu lại công đoạn nếu có số lượng > 0
            if ($calculatedQuantity !== 0) {
                $line_must_run[] = $lineId;
            }

            // Lưu kết quả tính được
            $stepQuantities[$lineId] = $calculatedQuantity;

            // Tính thời gian (giờ) nếu có năng suất
            $efficiencySpec = $efficiencies[$lineId] ?? throw new Exception("Không tìm thấy năng suất cho sản phẩm $productId tại công đoạn $lineName", 1);
            if ($efficiencySpec > 0) {
                // round(..., 2) làm tròn 2 chữ số thập phân
                $productionTimes[$lineId] = round($calculatedQuantity / $efficiencySpec, 2);
            }

            // Số lượng đầu ra của công đoạn này = đầu vào cho công đoạn kế
            $initialQuantity = $calculatedQuantity;
        }

        // 6. Lấy thứ tự công đoạn sắp xếp (ordered)
        $orderedSteps = $this->getOrderedProductionSteps($productId)
            ->filter(fn($value) => in_array($value->line_id, $line_must_run))
            ->values();
        // Nếu không có công đoạn nào phải chạy => không cần tính tiếp
        if ($orderedSteps->count() <= 0) {
            return null;
        }
        foreach ($orderedSteps as $key => $step) {
            if ($key == 0 && $step->line_id == 24) {
                $step->product_id = $materialId;
            } else {
                $step->product_id = $productId;
            }
        }
        // 7. Chuẩn bị các biến để tính lô/lots
        $stepEndTimes      = [];
        $lots              = [];
        $plans             = [];
        $lot_plans         = [];
        $machine_input     = [];
        $machine_in_line   = [];

        // Tạo mã lô sản xuất (LOSX)
        $losx_id  = Losx::generateUniqueIdPreview($orderIndex);
        $lo_sx    = Losx::where('product_order_id', $orderId)->first();
        if ($lo_sx) {
            $losx_id = $lo_sx->id;
        }

        // Lưu lại input để cập nhật/khởi tạo Losx
        $losx_input = [
            'product_order_id' => $orderId,
            'id'               => $losx_id,
        ];

        // 8. Lấy các thông số về lô
        $lineLotSize          = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'so-luong');
        $rollChangeTimes      = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'thoi-gian-len-xuong-cuon');
        $rollsPerTransports   = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'so-luong-cuon-1-lan-van-chuyen-cuon');
        $lineSetupTime        = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'vao-hang-setup-may');
        $preparationTimeSpecs = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'chuan-bidau-ca');
        $transportTimeSpecs   = $this->getSpecByKey($lineProductionArray, $productId, $materialId, 'van-chuyen-chuyen-hang-cong-doan-truoc-sang-cong-doan-sau');

        // 9. Vòng lặp các công đoạn đã sắp xếp
        $startTime = null;
        foreach ($orderedSteps as $index => $step) {
            $productId = $step->product_id;
            $lineId   = $step->line_id;
            $quantity = $stepQuantities[$lineId];

            // Mặc định 1 lot = 11000 (nếu lấy từ spec thì dùng spec, nếu không có thì gán mặc định)
            $lotSize  = $lineLotSize[$lineId] ?? 11000;

            // Tạo mảng lots cho lineId này
            if (!isset($lots[$lineId])) {
                $lots[$lineId] = [];
            }

            $product = Product::find($productId);
            if (!$product) {
                throw new Exception("Không tìm thấy mã sản phẩm " . $productId, 1);
            }

            $rollChangeTime = $rollChangeTimes[$lineId] ?? throw new Exception("Không tìm thấy thời gian lên xuống cuộn cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $efficiency     = $efficiencies[$lineId] ?? throw new Exception("Không tìm thấy năng suất cho sản phẩm $productId tại công đoạn " . $step->line->name, 1);
            // taskTime = số phút để làm xong 1 sản phẩm (minute/product)
            $taskTime       = $efficiency > 0 ? 60 / $efficiency : 0;

            // Số lượng cuộn 1 lần vận chuyển
            $rollsPerTransport = $rollsPerTransports[$lineId] ?? throw new Exception("Không tìm thấy số lượng cuộn 1 lần vận chuyển cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $setupTime         = $lineSetupTime[$lineId] ?? throw new Exception("Không tìm thấy thời gian vào hàng setup máy cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $shiftPrepTime     = $preparationTimeSpecs[$lineId] ?? throw new Exception("Không tìm thấy thời gian chuẩn bị ca cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);
            $transportTime     = $transportTimeSpecs[$lineId] ?? throw new Exception("Không tìm thấy thời gian vận chuyển cho sản phẩm $productId tại công đoạn " . ($step->line->name ?? ""), 1);

            // 9.1. Tính $startTime cho công đoạn đầu tiên hay tiếp theo
            if (!$startTime) {
                // Công đoạn đầu tiên => bắt đầu từ 7h30 sáng ngày hôm sau
                $startTime = Carbon::now()->addDay()->startOfDay();
            } else {
                // Điều chỉnh rollsPerTransport nếu cần
                $rollsPerTransport = $this->adjustRollsPerTransport(
                    $rollsPerTransport,
                    $quantity,
                    $lotSize,
                    $index,
                    $orderedSteps,
                    $inputWaste
                );

                // Lấy endTime của công đoạn trước => + transportTime
                //   (Cần cẩn thận kiểm tra index - 1, máy, v.v...)
                try {
                    $prevLineId = $orderedSteps[$index - 1]->line_id ?? null;
                    if (
                        $prevLineId
                        && isset($machine_in_line[$prevLineId])
                        && $machine_in_line[$prevLineId] > 0
                    ) {
                        $prevMachineCount = $machine_in_line[$prevLineId];
                        $transportIndex   = ($rollsPerTransport / $prevMachineCount) - 1;
                        // Log::debug([$prevLineId, $prevMachineCount, $transportIndex, $rollsPerTransport]);
                        // Kiểm tra phần tử lots cũ
                        if (
                            (!isset($lineInventory[$prevLineId]) || $lineInventory[$prevLineId] <= 0) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1]) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1][$transportIndex]) &&
                            isset($lots[$prevLineId][$prevMachineCount - 1][$transportIndex]['endTime'])
                        ) {
                            $prevEndTime = $lots[$prevLineId][$prevMachineCount - 1][$transportIndex]['endTime'];
                            $startTime   = $prevEndTime->copy()->addMinutes($transportTime);
                        }
                    }
                } catch (\Throwable $th) {
                    throw $th;
                }
            }

            // 9.2. Lấy máy có sẵn
            $numMachines = $numberMachineByStep[$lineId] ?? 0;
            $machines    = $this->getMachineReadyV2($lineId, $numMachines, $productId, $machine_available_list, $startTime, $machine_load_factors);

            $numMachines = count($machines);
            $machine_in_line[$lineId] = $numMachines;

            // Mỗi máy xử lý 1 phần quantity
            $quantityPerMachine = $numMachines > 0 ? ceil($quantity / $numMachines) : $quantity;
            $numLots            = ceil($quantityPerMachine / $lotSize);
            $lotIndexOffset     = 0;

            // 9.3. Vòng lặp từng máy
            foreach ($machines as $machineIndex => $machine) {
                //Khởi tạo thời gian bắt đầu/kết thúc cho kế hoạch
                $planStartTime = $startTime;

                list($planStartTime, $planEndTime, $shiftId) = $this->calculateProductionBatchTime(
                    $planStartTime,
                    $quantityPerMachine,
                    $efficiency,
                    $machine->code,
                    $machine_load_factors
                );

                // 9.4. Xây dựng danh sách các lots chi tiết
                $lot_in_plan = [];
                // Đếm số lot đã có trong InfoCongDoan
                $countLot = InfoCongDoan::query()->where([
                    'lo_sx'   => $losx_id,
                    'line_id' => $lineId
                ])->count() + $lotIndexOffset;

                $lotEndTime = $planStartTime;

                for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
                    $countLot++;
                    // Tạo lot_id (vd: LOSX123.L.0001)
                    $lotId = $losx_id . '.L.' . str_pad($countLot, 4, '0', STR_PAD_LEFT);

                    // Tính thời gian start - end cho lot
                    $lotStartTime = ($lotIndex == 1) ? $planStartTime : $lotEndTime;

                    // Ở đây logic cũ có chỗ chia “quantity % lotSize” cho lot đầu tiên:
                    // - T tuỳ chỉnh lại thành quantityPerLot = $lotSize, trừ trường hợp lot đầu tiên
                    //   trên máy đầu tiên, ta có thể “tận dụng” remainder. 
                    $quantityPerLot = ($lotIndex == 1 && $machineIndex == 0) ? ($quantity % $lotSize ?: $lotSize) : $lotSize;

                    //Tính thời gian sản xuất cho 1 lot của máy theo phút
                    $lotTime = ($quantityPerLot / $efficiency) * 60;
                    // Điều chỉnh thời gian theo ca
                    // list($lotStartTime, $lotEndTime) = $this->adjustTimeWithinShiftV2(
                    //     $lotStartTime,
                    //     $lotTime,
                    //     $machine->code,
                    //     $shiftPrepTime,
                    //     $machine_load_factors
                    // );
                    $lotEndTime = $lotStartTime->copy()->addMinutes($lotTime);

                    // // Kiểm tra thời gian kết thúc vượt deadline
                    // if ($order->delivery_date && $lotEndTime->greaterThan(Carbon::parse($order->delivery_date))) {
                    //     throw new Exception("Đơn hàng " . $order->id . " vượt quá thời gian giao hàng", 1);
                    // }

                    // Tạo dữ liệu lot
                    $lotPlanInput = [
                        'lot_id'           => $lotId,
                        'lo_sx'            => $losx_id,
                        'line_id'          => $lineId,
                        'product_id'       => $productId,
                        'machine_code'     => $machine->code,
                        'start_time'       => $lotStartTime,
                        'end_time'         => $lotEndTime,
                        'quantity'         => $quantityPerLot,
                        'lot_size'         => $quantityPerLot,
                        'product_order_id' => $orderId,
                        'customer_id'      => $order->customer_id,
                        'sl_giao_sx'       => $quantityPerLot,
                        'ca_sx'            => 1,
                        'cong_doan_sx'     => $step->line->name,
                        'machine_id'       => $machine->code,
                        'ten_san_pham'     => $product->name ?? "",
                        'khach_hang'       => $order->customer_id,
                        'thoi_gian_bat_dau' => $lotStartTime,
                        'thoi_gian_ket_thuc' => $lotEndTime,
                        'efficiency'       => $efficiency,
                    ];

                    // Thêm lot vào danh sách
                    $lot_plans[]  = $lotPlanInput;
                    $lot_in_plan[] = $lotPlanInput;

                    // Lưu vào lots chung
                    $lots[$lineId][$machineIndex][] = [
                        'lot_id'    => $lotId,
                        'quantity'  => $quantityPerLot,
                        'startTime' => $lotStartTime,
                        'endTime'   => $lotEndTime,
                    ];

                    // //Gán thời gian kết thúc của công đoạn
                    // $planEndTime = $lotEndTime;
                }

                $planInput = [
                    'product_order_id'  => $orderId,
                    'ngay_dat_hang'     => $order->order_date,
                    'ngay_sx'           => $planStartTime,
                    'ngay_giao_hang'    => $order->delivery_date,
                    'line_id'           => $lineId,
                    'cong_doan_sx'      => $step->line->name,
                    'ca_sx'             => 1,
                    'delivery_date'     => $order->delivery_date ? date('Y-m-d', strtotime($order->delivery_date)) : null,
                    'machine_id'        => $machine->code,
                    'product_id'        => $productId,
                    'ten_san_pham'      => $product->name ?? "",
                    'khach_hang'        => $order->customer->name ?? "",
                    'lo_sx'             => $losx_id,
                    'thu_tu_uu_tien'    => 1,
                    'nhan_luc'          => 1,
                    'tong_tg_thuc_hien' => ($quantity * $taskTime)
                        + ($rollChangeTime * $numLots)
                        + $setupTime,
                    'thoi_gian_bat_dau' => $planStartTime,
                    'thoi_gian_ket_thuc' => $planEndTime,
                    'sl_giao_sx'        => $quantityPerMachine,
                    'shift_id' => $shiftId,
                ];

                // Cập nhật plan
                $planInput['lots']            = $lot_in_plan;
                $planInput['children']            = $lot_in_plan;
                $plans[] = $planInput;

                $lotIndexOffset += $numLots;
            }
        }

        // 10. Tổng hợp dữ liệu lô sản xuất (LOSX)
        $losx_input['lo_sx']           = $losx_id;
        $losx_input['sl_giao_sx']      = $order->sl_giao_sx;
        $losx_input['product_id']      = $productId;
        $losx_input['product_name']    = $product->name ?? "";
        $losx_input['thoi_gian_bat_dau'] = !empty($plans) ? (reset($plans)['thoi_gian_bat_dau'] ?? null) : null;
        $losx_input['thoi_gian_ket_thuc'] = !empty($plans) ? (end($plans)['thoi_gian_ket_thuc'] ?? null) : null;
        $losx_input['khach_hang']      = $order->customer->name ?? "";
        $losx_input['delivery_date']   = $order->delivery_date ? date('d/m/Y', strtotime($order->delivery_date)) : null;
        $losx_input['plans']           = $plans;

        // 11. Trả về dữ liệu
        return [
            'lots'     => $lot_plans,
            'plans'    => $plans,
            'lo_sx'    => $losx_input,
            'machine_load_factors' => array_values($machine_load_factors),
        ];
    }

    public function adjustTimeWithinShiftV2($startTime, $productionDuration, $machineId, $shiftPreparationTime, $machine_load_factors = [])
    {
        // Chuyển đổi $startTime sang đối tượng Carbon với múi giờ 'Asia/Bangkok' nếu chưa phải đối tượng Carbon
        if (!$startTime instanceof Carbon) {
            $startTime = Carbon::parse($startTime, 'Asia/Bangkok');
        } else {
            $startTime->setTimezone('Asia/Bangkok');
        }

        // Khởi tạo biến xử lý
        $endTime = $startTime->copy(); // Thời gian hiện tại đang xử lý
        $maxDays = 30; // Giới hạn số ngày tối đa để tìm ca làm việc (tránh vòng lặp vô hạn)
        $daysProcessed = 0; // Số ngày đã duyệt

        //Tính startTIme
        while ($daysProcessed < $maxDays) {
            $dateString = $startTime->copy()->startOfDay()->toDateString(); // Lấy ngày hiện tại (bỏ phần giờ phút)
            $shifts = $this->getMachineProductionShiftsV2($machineId, $dateString);
            // Nếu không có ca làm việc, chuyển sang ngày tiếp theo
            if (!count($shifts)) {
                $startTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }
            $machineLoadFactors = $this->getMachineLoadFactors($machineId, $dateString, $machine_load_factors);
            $productionShiftStart = $shifts['start'];
            $productionShiftEnd = $shifts['end'];
            $totalWorkMinutes = ($machineLoadFactors->work_hours ?? 0) * 60;
            if (!$productionShiftStart->copy()->addMinutes($totalWorkMinutes)->greaterThan($productionShiftEnd)) {
                $startTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }
            $startTime = $productionShiftStart->copy()->addMinutes($totalWorkMinutes);
            break;
        }
        $daysProcessed = 0;
        $endTime = $startTime->copy();
        // Lặp cho đến khi thời gian còn lại được xử lý hoặc đạt giới hạn số ngày
        while ($productionDuration > 0 && $daysProcessed < $maxDays) {
            $endTime->addMinutes($productionDuration);

            // Lấy danh sách ca làm việc của máy vào ngày hiện tại
            $shifts = $this->getMachineProductionShiftsV2($machineId, $dateString);

            // Nếu không có ca làm việc, chuyển sang ngày tiếp theo
            if (!count($shifts)) {
                $endTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }

            // Xác định thời gian bắt đầu và kết thúc của ca làm việc
            $productionShiftStart = $shifts['start'];
            $productionShiftEnd = $shifts['end'];

            // Nếu $endTime nhỏ hơn $shiftStart và cùng ngày, dịch chuyển nó đến đầu ca
            if ($endTime->lessThan($productionShiftStart) && $endTime->day == $productionShiftStart->day) {
                $endTime = $productionShiftStart->copy()->addMinutes($shiftPreparationTime); // Cộng thêm thời gian chuẩn bị nếu không bắt đầu ngay
            }

            // Nếu $endTime đang nằm trong ca làm việc hoặc đúng thời gian bắt đầu ca
            if ($endTime->between($productionShiftStart, $productionShiftEnd)) {
                $productionTimeWithinShift = $productionShiftEnd->diffInMinutes($endTime, true); // Tính số phút còn lại trong ca

                // Nếu đủ thời gian để hoàn thành trong ca này
                if ($productionTimeWithinShift >= 0) {
                    return [$startTime, $endTime]; // Trả về thời gian bắt đầu & kết thúc
                } else {
                    // Nếu không đủ, cập nhật thời gian còn lại và chuyển sang ca tiếp theo
                    $productionDuration -= $productionTimeWithinShift;
                    $endTime->addDay()->startOfDay();
                    $daysProcessed++;
                    continue;
                }
            } elseif ($endTime->greaterThanOrEqualTo($productionShiftEnd)) {
                // Nếu $endTime đã vượt qua thời gian kết thúc ca, bỏ qua ca này
                $endTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }

            // Nếu không tìm được ca phù hợp, chuyển sang ngày tiếp theo
            $endTime->addDay()->startOfDay();
            $daysProcessed++;
        }

        // Trả về thời gian bắt đầu và thời gian kết thúc nếu không tìm được khoảng ca phù hợp
        return [$startTime, $endTime];
    }

    public function calculateProductionBatchTime($startTime, $quantityPerMachine, $efficiency, $machineId, &$machine_load_factors = [])
    {
        // Chuyển đổi $startTime sang đối tượng Carbon với múi giờ 'Asia/Bangkok' nếu chưa phải đối tượng Carbon
        if (!$startTime instanceof Carbon) {
            $startTime = Carbon::parse($startTime, 'Asia/Bangkok');
        } else {
            $startTime->setTimezone('Asia/Bangkok');
        }

        $productionDuration = ($quantityPerMachine / $efficiency) * 60;

        // Khởi tạo biến xử lý
        $maxDays = 30; // Giới hạn số ngày tối đa để tìm ca làm việc (tránh vòng lặp vô hạn)
        $daysProcessed = 0; // Số ngày đã duyệt

        //Tính toán thời gian làm việc của máy đã phân ca
        $daysProcessed = 0;
        $endTime = $startTime->copy();
        $planShift = null;
        while ($productionDuration > 0 && $daysProcessed < $maxDays) {
            $dateString = $endTime->copy()->startOfDay()->toDateString(); // Lấy ngày hiện tại (bỏ phần giờ phút)
            $shifts = $this->getMachineProductionShiftsV2($machineId, $dateString);

            // Nếu không có ca làm việc, chuyển sang ngày tiếp theo
            if (!count($shifts)) {
                $endTime->addDay()->startOfDay();
                $daysProcessed++;
                continue;
            }
            $date = $dateString;
            foreach ($shifts as $key => $shift) {
                $productionShiftStart = Carbon::createFromFormat('Y-m-d H:i:s', $date . " " . $shift->shift->start_time);
                $productionShiftEnd = Carbon::createFromFormat('Y-m-d H:i:s', $date . " " . $shift->shift->end_time);

                if ($productionShiftEnd->lessThan($productionShiftStart)) {
                    $productionShiftEnd->addDay();
                }

                //Lấy hệ số tải của máy theo ca
                $factor_key = $machineId . '_' . $dateString . '_' . $shift->shift_id;
                if (!isset($machine_load_factors[$factor_key])) {
                    $machine_load_factors[$factor_key] = [
                        'machine_code' => $machineId,
                        'date' => $dateString,
                        'fixed_productivity_per_hour' => $efficiency,
                        'work_hours' => 0,
                        'fixed_hours' => $productionShiftEnd->diffInMinutes($productionShiftStart) / 60,
                        'shift_id' => $shift->shift_id
                    ];
                }
                if ($productionShiftEnd->lessThan($productionShiftStart->copy()->addMinutes(($machine_load_factors[$factor_key]['work_hours'] ?? 0) * 60))) {
                    //Nếu thời gian bắt đầu + thời gian kết thúc lớn hơn thời gian kết thúc chuyển sang ca tiếp theo
                    continue;
                }
                if (!$planShift) {
                    $planShift = $shifts[0]->shift_id;
                    $startTime = $productionShiftStart->copy();
                }
                $remainShiftDuration = $productionShiftEnd->diffInMinutes($productionShiftStart->copy()->addMinutes($machine_load_factors[$factor_key]['work_hours'] * 60));
                Log::debug('Machine: ' . $machineId);
                Log::debug($machine_load_factors[$factor_key]);
                Log::debug([$productionDuration, $remainShiftDuration]);
                Log::debug([$productionShiftStart->toDateTimeString(), $productionShiftEnd->toDateTimeString()]);
                if ($productionDuration <= $remainShiftDuration) {
                    $machine_load_factors[$factor_key]['work_hours'] = ($machine_load_factors[$factor_key]['work_hours'] ?? 0) + ($productionDuration / 60);
                    $productionDuration = 0;
                    $endTime = $productionShiftStart->copy()->addMinutes($machine_load_factors[$factor_key]['work_hours'] * 60);
                    Log::debug(['break', $startTime->toDateTimeString(), $endTime->toDateTimeString()]);
                    return [$startTime, $endTime, $planShift];
                } else {
                    $machine_load_factors[$factor_key]['work_hours'] = ($machine_load_factors[$factor_key]['work_hours'] ?? 0) + ($remainShiftDuration / 60);
                    $productionDuration -= $remainShiftDuration;
                    $endTime = $productionShiftStart->copy()->addMinutes($machine_load_factors[$factor_key]['work_hours'] * 60);
                }
                Log::debug([$startTime->toDateTimeString(), $endTime->toDateTimeString()]);
            }
            $daysProcessed++;
        }
        // Trả về thời gian bắt đầu và thời gian kết thúc nếu không tìm được khoảng ca phù hợp
        return [$startTime, $endTime, $planShift];
    }

    function getMachineProductionShiftsV2($machineId, $startDate)
    {
        $cacheKey = "machine_{$machineId}_production_shifts_v2_{$startDate}";

        return Cache::remember($cacheKey, 10, function () use ($machineId, $startDate) {
            // Lấy tất cả các shift_id của máy trong ngày
            $machineShifts = MachineShift::where('machine_id', $machineId)
                ->where('date', $startDate)
                ->has('shift')
                ->with(['shift'])
                ->get();

            if ($machineShifts->isEmpty()) {
                return [];
            }

            return $machineShifts;

            // $shiftBreaks = $machineShifts->flatMap->shiftBreak;

            // if ($shiftBreaks->isEmpty()) {
            //     return [];
            // }

            // // Lấy thời gian bắt đầu sớm nhất
            // $startTime = $shiftBreaks->first()->start_time;
            // $start = Carbon::createFromFormat('Y-m-d H:i:s', "$startDate $startTime");

            // // Tính tổng thời gian sản xuất trong các ca được chọn
            // $endTime = $shiftBreaks->last()->end_time;
            // $end = Carbon::createFromFormat('Y-m-d H:i:s', "$startDate $endTime");
            // if($endTime <= $startTime){
            //     $end->addDay();
            // }

            // return [
            //     'start' => $start,
            //     'end'   => $end,
            //     'shift_quantity' => count($machineShifts)
            // ];
        });
    }

    public function createProductionPlan(Request $request)
    {
        // return $request->all();
        $plans = $request->plans ?? [];
        if (count($plans) <= 0) {
            return $this->failure('', 'Không có dữ liệu kế hoạch lô');
        }
        $lots = $request->lots ?? [];
        if (count($lots) <= 0) {
            return $this->failure('', 'Không có dữ liệu kế hoạch lot');
        }
        $machines = $request->machines ?? [];
        $lo_sx = $request->lo_sx ?? [];
        $machine_load_factors = $request->machine_load_factors ?? [];
        try {
            DB::beginTransaction();
            foreach ($plans as $plan) {
                $production_plan = ProductionPlan::create($plan);
                foreach ($plan['lots'] ?? [] as $lot_plan) {
                    $lot_plan['production_plan_id'] = $production_plan->id;
                    LotPlan::create($lot_plan);
                }
            }
            foreach ($machines as $machine) {
                Machine::where('code', $machine['machine_code'])->update(['available_at' => $machine['available_at']]);
            }
            foreach ($lo_sx as $value) {
                Losx::updateOrCreate(['id' => $value['id']], ['product_order_id' => $value['product_order_id']]);
                $product_order = ProductOrder::find($value['product_order_id']);
                if ($product_order) {
                    $product_order->update(['sl_da_giao' => $product_order->sl_giao_sx, 'sl_giao_sx' => 0]);
                }
            }
            foreach ($machine_load_factors as $load_factor) {
                MachineLoadFactor::updateOrCreate([
                    'machine_code' => $load_factor['machine_code'],
                    'date' => $load_factor['date'],
                ], $load_factor);
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->failure($th->getMessage(), 'Lỗi tạo kế hoạch');
        }
        return $this->success('', 'Đã tạo thành công');
    }

    public function printProductionPlan(Request $request)
    {
        $plans = $request->plans ?? [];
        if (count($plans) <= 0) {
            return $this->failure('', 'Không có dữ liệu kế hoạch lô');
        }
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Đặt tên tiêu đề bảng
        $header = [
            '',
            'Thứ tự ưu tiên',
            'Thời gian bắt đầu(h)',
            'Thời gian kết thúc(h)',
            'Ngày SX',
            'Ca SX',
            'Công đoạn SX',
            'Máy sản xuất',
            'Mã sản phẩm',
            'Khách hàng',
            'Tên Sản Phẩm',
            'Mã đơn hàng',
            'Ngày giao hàng',
            'SL Tổng ĐH (đvt: túi/mảnh)',
            'SL NVL đầu vào (ĐVT: Tờ)',
            'SL thành phẩm (ĐVT: Tờ)',
            'SL giao SX (đvt: túi/mảnh)',
            'KQSX (đvt: túi/mảnh)',
            'SL còn lại (đvt: túi/mảnh)',
            'Khổ Giấy (mm)',
            'Tốc độ',
            'UPH',
        ];

        // Thiết lập các cột tiêu đề
        foreach ($header as $col => $title) {
            $sheet->setCellValue([$col + 1, 3], $title);
        }

        // Duyệt dữ liệu $plans và ghi vào file Excel
        $rowIndex = 4;
        foreach ($plans as $index => $plan) {
            $sheet->setCellValue("B$rowIndex", $plan['thu_tu_uu_tien']);
            $sheet->setCellValue("C$rowIndex", date('Y-m-d H:i:s', strtotime($plan['thoi_gian_bat_dau'])));
            $sheet->setCellValue("D$rowIndex", date('Y-m-d H:i:s', strtotime($plan['thoi_gian_ket_thuc'])));
            $sheet->setCellValue("E$rowIndex", date('Y-m-d', strtotime($plan['ngay_sx'])));
            $sheet->setCellValue("F$rowIndex", $plan['ca_sx']);
            $sheet->setCellValue("G$rowIndex", $plan['cong_doan_sx']);
            $sheet->setCellValue("H$rowIndex", $plan['machine_id']);
            $sheet->setCellValue("I$rowIndex", $plan['product_id']);
            $sheet->setCellValue("J$rowIndex", $plan['khach_hang']);
            $sheet->setCellValue("K$rowIndex", $plan['ten_san_pham']);
            $sheet->setCellValue("L$rowIndex", $plan['product_order_id']);
            $sheet->setCellValue("M$rowIndex", date('Y-m-d', strtotime($plan['delivery_date'])));
            $sheet->setCellValue("Q$rowIndex", $plan['sl_giao_sx']);
            $rowIndex++;
        }

        foreach (range('A', 'W') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
        $highestRow = $rowIndex - 1;
        $highestColumn = 'W';
        $sheet->getStyle("B3:$highestColumn$highestRow")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => '000000'],
                ],
            ],
        ]);

        $filePath = "exported_files/KHSX_output.xlsx";
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        return $this->success(url($filePath), 'Đã tạo file Excel thành công');
    }

    public function uploadProductionPlan(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '2048M');
        $extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $reader = match ($extension) {
            'csv' => new \PhpOffice\PhpSpreadsheet\Reader\Csv(),
            'xlsx' => new \PhpOffice\PhpSpreadsheet\Reader\Xlsx(),
            default => new \PhpOffice\PhpSpreadsheet\Reader\Xls()
        };

        $spreadsheet = $reader->load($_FILES['file']['tmp_name']);
        $allDataInSheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $productIds = array_unique(array_map(fn($row) => trim($row['I']), array_slice($allDataInSheet, 4)));
        $existingProductIds = Product::whereIn('id', $productIds)->pluck('id')->toArray();
        $missingProductIds = array_diff($productIds, $existingProductIds);

        if (!empty($missingProductIds)) {
            throw new Exception("Các mã sản phẩm sau chưa được khai báo " . implode(', ', $missingProductIds), 1);
        }

        DB::beginTransaction();
        try {
            $stepEndTimes = [];

            foreach ($allDataInSheet as $key => $row) {
                if ($key <= 3) continue;

                $machine = Machine::where('code', preg_replace('/\s+/', '', $row['H']))->first();
                if (!$machine) throw new Exception("Không tìm thấy máy " . $row['H']);

                // if ($request->user()->username !== 'admin') {
                //     if ($machine->line_id != 29) {
                //         return $this->failure('', 'Không thể upload kế hoạch ngoài công đoạn chọn');
                //     }
                // }

                $line = Line::find($machine->line_id);
                if (!$line) throw new Exception("Không tìm thấy công đoạn");

                $input = $this->mapInputData($row, $machine->line_id);
                $product = Product::find($input['product_id']);
                if (!$product) throw new Exception("Không tìm thấy mã sản phẩm " . $input['product_id']);

                $startTime = $input['thoi_gian_bat_dau'];

                $endTime = $this->calculateEndTime($input, $startTime, $product->id, $machine->line_id);

                $order = $this->getOrder($input, $product);
                $lo_sx = Losx::firstOrCreate(['product_order_id' => $order->id]);
                $losx_id = $lo_sx->id;
                $plan = $this->storeProductionPlan($input, $losx_id, $startTime, $endTime, $order, $line, $machine);
                $lastLotPLan = $this->generateLots($input, $losx_id, $plan, $startTime, $machine);
                if ($lastLotPLan) {
                    $plan->update(['thoi_gian_ket_thuc' => $lastLotPLan->end_time]);
                }
            }
            DB::commit();
        } catch (\Exception $ex) {
            DB::rollBack();
            return $this->failure([], $ex->getMessage(), 500);
        }
        return $this->success('', 'Đã tạo thành công');
    }

    private function storeProductionPlan($input, $losx_id, $startTime, $endTime, $order, $line, $machine)
    {
        $planInput = [
            'product_order_id' => $order->id,
            'ngay_dat_hang' => $order->order_date,
            'ngay_sx' => $startTime,
            'ngay_giao_hang' => $order->delivery_date,
            'line_id' => $line->id,
            'cong_doan_sx' => $line->name,
            'ca_sx' => 1,
            'delivery_date' => $order->delivery_date ? date('Y-m-d', strtotime($order->delivery_date)) : null,
            'machine_id' => $machine->code,
            'product_id' => $input['product_id'],
            'ten_san_pham' => $order->product->name ?? '',
            'khach_hang' => $order->customer->name ?? "",
            'lo_sx' => $losx_id,
            'thu_tu_uu_tien' => $input['thu_tu_uu_tien'],
            'nhan_luc' => $input['nhan_luc'],
            'tong_tg_thuc_hien' => $input['tong_tg_thuc_hien'],
            'thoi_gian_bat_dau' => $startTime,
            'thoi_gian_ket_thuc' => $endTime,
            'sl_giao_sx' => $input['sl_giao_sx'],
            'status_plan' => 0
        ];

        return ProductionPlan::create($planInput);
    }

    public static function adjustShift($shiftStart, $shiftEnd, $shiftBreaks)
    {
        // Thiết lập múi giờ mong muốn
        $timezone = new DateTimeZone('Asia/Ho_Chi_Minh');

        // Chuyển đổi thời gian ca làm việc sang đối tượng DateTime
        $start = new DateTime($shiftStart, $timezone);
        $end   = new DateTime($shiftEnd, $timezone);
        // Tính số phút của ca làm việc ban đầu
        $productionMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        $totalBreakMinutes = 0;
        $date = $start->format('Y-m-d');
        // Duyệt qua các khoảng break
        foreach ($shiftBreaks as $break) {
            if (isset($break->type_break) && $break->type_break == 'Nghỉ') {
                $breakStartStr = $date . ' ' . $break->start_time;
                $breakEndStr = $date . ' ' . $break->end_time;

                $breakStart = new DateTime($breakStartStr, $timezone);
                $breakEnd   = new DateTime($breakEndStr, $timezone);
                // Nếu khoảng break nằm hoàn toàn ngoài ca thì bỏ qua
                if ($breakEnd <= $start || $breakStart >= $end) {
                    continue;
                }

                // Tính khoảng nghỉ hiệu quả nằm trong ca
                $effectiveBreakStart = $breakStart > $start ? $breakStart : $start;
                $effectiveBreakEnd   = $breakEnd < $end ? $breakEnd : $end;
                $duration = ($effectiveBreakEnd->getTimestamp() - $effectiveBreakStart->getTimestamp()) / 60;

                $totalBreakMinutes += $duration;
            }
        }

        // Điều chỉnh thời gian kết thúc ca bằng cách cộng thêm số phút nghỉ
        $adjustedEndTimestamp = $end->getTimestamp() + $totalBreakMinutes * 60;
        $adjustedEnd = new DateTime();
        $adjustedEnd->setTimestamp($adjustedEndTimestamp);
        $adjustedEnd->setTimezone($timezone);

        return [
            'start_time'     => $start->format('Y-m-d H:i:s'),
            'end_time'       => $adjustedEnd->format('Y-m-d H:i:s'),
            // production_time tính theo ca ban đầu (không cộng thêm break)
            'production_time' => gmdate("H:i", $productionMinutes * 60),
            'break_time'      => gmdate("H:i", $totalBreakMinutes * 60),
        ];
    }

    protected function getPrioritizedMachine($line_id, $product_id, $machine_load_factors, $maxProductionMinutes, $except_machine = [])
    {
        // Lấy tất cả các máy theo line_id và product_id theo thứ tự ưu tiên ban đầu
        $machinePriorityOrders = MachinePriorityOrder::where('line_id', $line_id)
            ->where('product_id', $product_id)
            ->orderBy('priority', 'asc')
            ->get();
        if ($line_id == 29) {
            $priority = 1;
            $machinePriorityOrders = Machine::where('line_id', $line_id)
                ->get()->sortBy('code', SORT_NATURAL)->map(function ($machine) use (&$priority, $product_id) {
                    $machine->machine_id = $machine->code;
                    $machine->priority = $priority;
                    $machine->product_id = $product_id;
                    $priority++;
                    return $machine;
                });
        }

        $acceptableMachines = [];      // Những máy đáp ứng điều kiện (chưa sử dụng hoặc có đủ chỗ cho maxProductionMinutes)
        $nonAcceptableMachines = [];   // Những máy đã sử dụng nhưng không đáp ứng điều kiện

        foreach ($machinePriorityOrders as $machinePriorityOrder) {
            // Nếu máy chưa có trong machine_load_factors, nghĩa là chưa được sử dụng => chấp nhận
            if (!array_key_exists($machinePriorityOrder->machine_id, $machine_load_factors)) {
                $acceptableMachines[] = $machinePriorityOrder;
            } else {
                // Máy đã sử dụng, kiểm tra điều kiện: work_hours hiện tại + maxProductionMinutes <= fixed_hours
                $data = $machine_load_factors[$machinePriorityOrder->machine_id];
                if (($data['work_hours'] + $maxProductionMinutes) <= $data['fixed_hours']) {
                    $acceptableMachines[] = $machinePriorityOrder;
                } else {
                    $nonAcceptableMachines[] = $machinePriorityOrder;
                }
            }
        }

        // Nếu có máy đáp ứng điều kiện, trả về máy đầu tiên theo thứ tự ưu tiên
        if (!empty($acceptableMachines)) {
            return $acceptableMachines[0];
        }

        // Nếu không có máy nào đáp ứng, chọn trong số máy đã sử dụng (nonAcceptableMachines) máy có work_hours nhỏ nhất
        if (!empty($nonAcceptableMachines)) {
            usort($nonAcceptableMachines, function ($a, $b) use ($machine_load_factors) {
                return $machine_load_factors[$a->machine_id]['work_hours'] <=> $machine_load_factors[$b->machine_id]['work_hours'];
            });
            return $nonAcceptableMachines[0];
        }

        // Nếu không tìm thấy máy nào, trả về null (hoặc xử lý theo nghiệp vụ)
        return null;
    }

    public function storeProductionPlanAuto(Request $request)
    {
        $productionOrderPriorities = ProductionOrderPriority::with('productionOrder', 'productionOrderHistory')->orderBy('priority', 'asc')->get();
        $productionPlans = [];
        $machine_load_factors = [];
        foreach ($productionOrderPriorities as $productionOrderPriority) {
            $sortedHistories = $productionOrderPriority->productionOrderHistory->sortByDesc('updated_at');
            foreach ($sortedHistories as $key => $history) {
                $setupTime = $this->getSetupTime($productionOrderPriority->product_id, $history->line_id);
                $remainQuantityOrder = $history->order_quantity - $history->inventory_quantity;
                if ($remainQuantityOrder <= 0) {
                    continue;
                }
                $efficiency = $this->getEfficiency($productionOrderPriority->product_id, $history->line_id);
                if ($efficiency <= 0) {
                    throw new Exception("Không tìm thấy năng suất cho sản phẩm " . $productionOrderPriority->product_id . " và công đoạn " . $history->line->name, 1);
                }
                $productionTime = ceil(($remainQuantityOrder / $efficiency) * 60) + $setupTime;

                $machinePriorityOrder = $this->getPrioritizedMachine($history->line_id, $productionOrderPriority->product_id, $machine_load_factors, $productionTime);
                if (empty($machinePriorityOrder)) {
                    continue;
                }
                $machineShifts = $this->getMachineProductionShifts($machinePriorityOrder->machine_id, date('Y-m-d', strtotime('+1 day')));
                if (count($machineShifts) <= 0) {
                    if ($history->line_id == 29) {
                        continue;
                    } else {
                        throw new Exception("Máy " . $machinePriorityOrder->machine_id . " chưa được phân ca ngày " . date('d-m-Y', strtotime('+1 day')), 1);
                    }
                }

                if (isset($machine_load_factors[$machinePriorityOrder->machine_id])) {
                    $totalTime = $machine_load_factors[$machinePriorityOrder->machine_id]['fixed_hours'] - $machine_load_factors[$machinePriorityOrder->machine_id]['work_hours'];
                    $start_time = $machine_load_factors[$machinePriorityOrder->machine_id]['available_at'];
                    if ($machine_load_factors[$machinePriorityOrder->machine_id]['product_id'] == $productionOrderPriority->product_id) {
                        $setupTime = 0;
                    }
                } else {
                    $totalTime = $machineShifts->where('type_break', 'Sản xuất')->sum('duration_minutes');
                    $start_time = date('Y-m-d ' . $machineShifts->first()->start_time, strtotime('+1 day'));
                }
                if ($totalTime <= 0 || $totalTime <= $setupTime) {
                    continue;
                }

                if ($history->line_id == 29) {
                    $machineShift = MachineShift::where('machine_id', $machinePriorityOrder->machine_id)
                        ->where('date', date('Y-m-d', strtotime('+1 day')))
                        ->where('shift_id', $machineShifts->first()->shift_id ?? null)
                        ->first();
                    Log::debug($machineShift);
                    $efficiency = ($machineShift->operator_quantity ?? 1) * $efficiency;
                }

                if ($productionTime > $totalTime) {
                    $productionTime = $totalTime;
                    $productionQuanty = ceil(($totalTime - $setupTime) * ($efficiency / 60));
                } else {

                    $productionQuanty = $remainQuantityOrder;
                }
                $end_time = date('Y-m-d H:i:s', strtotime('+' . $productionTime . ' minutes', strtotime($start_time)));
                $times = $this->adjustShift($start_time, $end_time, $machineShifts);

                $start_time = $times['start_time'];
                $end_time = $times['end_time'];
                $product_id = $productionOrderPriority->product_id;
                if ($history->line_id == 24) {
                    $bom = Bom::where('product_id', $productionOrderPriority->product_id)->where('priority', 1)->first();
                    if ($bom) {
                        $product_id = $bom->material_id;
                    }
                }
                $product = Product::find($product_id);
                $productionPlans[] = [
                    'product_order_id' => $productionOrderPriority->production_order_id,
                    'ngay_dat_hang' => $history->productionOrderPriority->confirm_date,
                    'cong_doan_sx' => $history->line->name,
                    'line_id' => $history->line_id,
                    'ca_sx' => 1,
                    'ngay_sx' => date('Y-m-d', strtotime('+1 day')),
                    'ngay_giao_hang' => '',
                    'machine_id' => $machinePriorityOrder->machine_id,
                    'product_id' => $product_id,
                    'product_name' => $product->name,
                    'khach_hang' => '',
                    'so_bat' => 0,
                    'sl_nvl' => 0,
                    'sl_tong_don_hang' => $history->order_quantity,
                    'sl_giao_sx' => $productionQuanty,
                    'sl_thanh_pham' => 0,
                    'thu_tu_uu_tien' => $machinePriorityOrder->priority,
                    'note' => '',
                    'UPH' => $efficiency,
                    'nhan_luc' => 1,
                    'tong_tg_thuc_hien' => $totalTime,
                    'kho_giay' => '',
                    'toc_do' => 0,
                    'thoi_gian_chinh_may' => $setupTime,
                    'thoi_gian_thuc_hien' => $totalTime - $setupTime,
                    'thoi_gian_bat_dau' => $start_time,
                    'thoi_gian_ket_thuc' => $end_time,
                    'status' => InfoCongDoan::STATUS_PLANNED
                ];
                $machine_load_factors[$machinePriorityOrder->machine_id] = [
                    'date' => date('Y-m-d', strtotime('+1 day')),
                    'fixed_productivity_per_hour' => $efficiency,
                    'work_hours' => $productionTime,
                    'fixed_hours' => $totalTime,
                    'shift_id' => 1,
                    'available_at' => $end_time,
                    'product_id' => $product_id,
                ];
            }
        }
        return $this->success($productionPlans, 'Tạo kế hoạch thành công');
    }

    public function approveProductionPlanAuto(Request $request)
    {
        $productionPlans = $request->productionPlanData;
        DB::beginTransaction();
        try {
            foreach ($productionPlans as $plan) {
                $lo_sx = Losx::firstOrCreate(['product_order_id' => $plan['product_order_id']]);
                $plan['lo_sx'] = $lo_sx->id;
                $plan['production_order_id'] = $plan['product_order_id'];
                $plan['khach_hang'] = $plan['khach_hang'] ?? '';
                $plan['ngay_giao_hang'] = $plan['ngay_giao_hang'] ?? '';
                ProductionPlan::create($plan);
            }
            DB::commit();
        } catch (\Exception $ex) {
            DB::rollBack();
            return $this->failure($ex->getMessage(), 'Lỗi tạo kế hoạch');
        }
        return $this->success('', 'Duyệt kế hoạch thành công');
    }

    private function mapInputData($row, $lineId)
    {
        return [
            'product_order_id' => $row['L'],
            'ngay_dat_hang' => Carbon::parse(str_replace('/', '-', $row['AD']))->format('Y-m-d'),
            'cong_doan_sx' => Str::slug($row['G']),
            'line_id' => $lineId,
            'ca_sx' => $row['F'],
            'ngay_sx' => Carbon::parse(str_replace('/', '-', $row['E']))->format('Y-m-d'),
            'ngay_giao_hang' => Carbon::parse(str_replace('/', '-', $row['M']))->format('Y-m-d'),
            'machine_id' => preg_replace('/\s+/', '', $row['H']),
            'product_id' => trim($row['I']),
            'product_name' => $row['K'],
            'khach_hang' => $row['J'],
            'so_bat' => $row['T'] ?? 0,
            'sl_nvl' => $row['O'],
            'sl_tong_don_hang' => $row['N'],
            'sl_giao_sx' => filter_var($row['Q'], FILTER_SANITIZE_NUMBER_INT),
            'sl_thanh_pham' => $row['P'] ?? 0,
            'thu_tu_uu_tien' => $row['B'],
            'note' => $row['AE'] ?? "",
            'UPH' => str_replace(',', '', $row['W']),
            'nhan_luc' => $row['AB'],
            'tong_tg_thuc_hien' => filter_var($row['AA'], FILTER_SANITIZE_NUMBER_INT),
            'kho_giay' => $row['U'] ?? "",
            'toc_do' => $row['V'] ? (int)$row['V'] : "",
            'thoi_gian_chinh_may' => $row['X'] ? (float)$row['X'] : "",
            'thoi_gian_thuc_hien' => $row['Y'] ? (float)$row['Y'] : "",
            'thoi_gian_bat_dau' => Carbon::parse($row['E'] . ' ' . $row['C']),
            'thoi_gian_ket_thuc' => Carbon::parse($row['E'] . ' ' . $row['D'] . (strtotime($row['C']) > strtotime($row['D']) ? " +1 day" : "")),
            'status' => InfoCongDoan::STATUS_PLANNED
        ];
    }

    private function getStartTime($input, $stepEndTimes, $machineCode, $oldMachineCode)
    {
        // if ($oldMachineCode !== $machineCode) {
        //     return $stepEndTimes[$machineCode] ?? Carbon::parse($input['thoi_gian_bat_dau']);
        // }
        return Carbon::parse($input['thoi_gian_bat_dau']);
    }

    public function calculateEndTime($input, $startTime, $productId, $lineId)
    {
        $quantity = $input['sl_giao_sx'];
        $lotSize = $this->getLotSize($productId, $lineId);
        $taskTime = $this->getTaskTime($productId, $lineId, $input['UPH']);
        $numLots = ceil($quantity / $lotSize);
        $rollChangeTime = $this->getRollChangeTime($productId, $lineId);
        $setupTime = $this->getSetupTime($productId, $lineId);

        $endTime = $startTime->copy()->addMinutes(((($taskTime * $lotSize) + $rollChangeTime) * $numLots) + $setupTime);
        return $endTime;
    }
    public function getTaskTime($productId, $lineId, $uph)
    {
        $efficiency = $this->getEfficiency($productId, $lineId);
        return $efficiency > 0 ? (60 / $efficiency) : ($uph > 0 ? (60 / $uph) : 0);
    }

    private function getOrder($input, $product)
    {
        $customer = Customer::firstOrCreate(
            ['name' => $input['khach_hang']],
            ['name' => $input['khach_hang'], 'id' => Str::slug($input['khach_hang'])]
        );
        $id = QueryHelper::generateNewId(new ProductOrder(), date('Ym'), 2);
        return ProductOrder::firstOrCreate(
            ['id' => $input['product_order_id']],
            [
                'id' => $id,
                'order_number' => $id,
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'order_date' => $input['ngay_dat_hang'],
                'quantity' => $input['sl_thanh_pham'],
                'delivery_date' => $input['ngay_giao_hang']
            ]
        );
    }


    private function generateLots($input, $losx_id, $plan, $startTime, $machine)
    {
        $lot_plan = null;
        $quantity = $input['sl_giao_sx'];
        $lotSize = $this->getLotSize($input['product_id'], $input['line_id']);
        $numLots = ceil($quantity / $lotSize);
        $lotEndTime = $startTime;
        $taskTime = $this->getTaskTime($input['product_id'],  $input['line_id'], $input['UPH']);
        $rollChangeTime = $this->getRollChangeTime($input['product_id'],  $input['line_id']);
        $setupTime = $this->getSetupTime($input['product_id'],  $input['line_id']);
        for ($lotIndex = 1; $lotIndex <= $numLots; $lotIndex++) {
            $lotId =  $losx_id . '.L.' . str_pad($lotIndex, 4, '0', STR_PAD_LEFT);
            $lotStartTime = ($lotIndex == 1) ? $startTime : $lotEndTime;
            $quantityPerLot = ($lotIndex == 1 && ($quantity % $lotSize != 0)) ? ($quantity % $lotSize) : $lotSize;
            if ($lotIndex == 1) {
                $lotEndTime = $lotStartTime->copy()->addMinutes(($taskTime * $lotSize) + $rollChangeTime + $setupTime);
            } else {
                $lotEndTime = $lotStartTime->copy()->addMinutes(($taskTime * $lotSize) + $rollChangeTime);
            }
            if ($input['line_id'] == 29) {
                $lotEndTime = $lotStartTime->copy()->addMinutes(30);
            }
            $lot_plan = LotPlan::create([
                'lot_id' => $lotId,
                'lo_sx' => $losx_id,
                'line_id' => $input['line_id'],
                'product_id' => $input['product_id'],
                'machine_code' => $machine->code,
                'start_time' => $lotStartTime,
                'end_time' => $lotEndTime,
                'quantity' => $quantityPerLot,
                'lot_size' => $quantityPerLot,
                'product_order_id' => $plan->product_order_id,
                'customer_id' => $plan->product_order_id,
                'sl_giao_sx' => $quantityPerLot,
                'ca_sx' => 1,
                'cong_doan_sx' => $plan->cong_doan_sx,
                'machine_id' => $machine->code,
                'ten_san_pham' => $plan->ten_san_pham,
                'khach_hang' => $plan->khach_hang,
                'thoi_gian_bat_dau' => $lotStartTime,
                'thoi_gian_ket_thuc' => $lotEndTime,
                'production_plan_id' => $plan->id
            ]);
        }
        return $lot_plan;
    }

    function createPrioritizedMachines(){
        $plans = ProductionPlan::whereNotNull('production_order_id')->get();
        $prioritizedMachines = [];
        foreach($plans as $plan){
            $key = $plan->machine_id . '_' . $plan->product_id;
            if(!isset($prioritizedMachines[$key])){
                $prioritizedMachines[$key] = [
                    'machine_id' => $plan->machine_id,
                    'product_id' => $plan->product_id,
                    'frequency' => 1
                ];
            }else{
                $prioritizedMachines[$key]['frequency']++;
            }
        }
        PrioritizedMachines::truncate();
        PrioritizedMachines::insert(array_values($prioritizedMachines));
    }

    function getMostUsedMachine($line_id, $product_id = ""){
        $line = Line::find($line_id);
        if(!$line){
            return [];
        }
        $machine_ids = $line->machines->pluck('code')->toArray();
        return PrioritizedMachines::orderBy('frequency', 'desc')->whereIn('machine_id', $machine_ids)->first();
    }
}
