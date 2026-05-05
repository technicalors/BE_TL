<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceSchedule;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MaintenancePlanController extends Controller
{
    use API;
    public function index(Request $request)
    {
        $query = MaintenanceSchedule::with('machine.line', 'maintenancePlan', 'maintenanceItem.maintenanceCategory', 'maintenanceLog');
        if(isset($request->date) && count($request->date) === 2) {
            $query->whereDate('due_date', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('due_date', '<=', date('Y-m-d', strtotime($request->date[1])));;
        }else{
            $query->whereDate('due_date', now());
        }
        if(isset($request->line_id)) {
            $lineId = $request->line_id;
            $query->whereHas('machine', function ($q) use ($lineId) {
                $q->whereIn('line_id', $lineId);
            });
        }
        $schedules = $query->get()->groupBy(function($item){
            return $item->machine_code . date('Y-m-d', strtotime($item->due_date));
        });
        $data = [];
        foreach ($schedules as $machine_code => $schedule) {
            $schedule->sortBy('due_date');
            $logs = $schedule->filter(function (object $item) {
                return $item->maintenanceLog;
            })->sortBy(function (object $item) {
                return $item->maintenanceLog->log_date;
            });
            $data[] = [
                'machine_code' => $schedule[0]->machine_code ?? "",
                'machine_name' => $schedule[0]->machine_code ?? "",
                'line_name' => $schedule[0]->machine->line->name ?? "",
                'item_number' => $schedule->count(),
                'planning_date' => date('d/m/Y', strtotime($schedule[0]->due_date)),
                'due_date' => $schedule[0]->due_date,
                'log_date' => isset($logs->first()->maintenanceLog) ? date('d/m/Y', strtotime($logs->first()->maintenanceLog->log_date)) : "",
            ];
        }
        return $this->success($data);
    }

    public function show($id)
    {
        return MaintenancePlan::find($id);
    }

    public function store(Request $request)
    {
        $maintenancePlan = MaintenancePlan::create($request->all());
        return response()->json($maintenancePlan, 201);
    }

    public function update(Request $request, $id)
    {
        $maintenancePlan = MaintenancePlan::findOrFail($id);
        $maintenancePlan->update($request->all());
        return response()->json($maintenancePlan, 200);
    }

    public function destroy($id)
    {
        MaintenancePlan::destroy($id);
        return response()->json(null, 204);
    }

    public function calendarTable(Request $request)
    {
        $query =  MaintenanceSchedule::with('machine.line', 'maintenanceLog');
        if (isset($request->date)) {
            $date = date_create($request->date);
            $query->whereMonth('due_date', $date->format('m'))->whereYear('due_date', $date->format('Y'));
        } else {
            $query->whereMonth('due_date', now())->whereYear('due_date', now());
        }
        if(isset($request->line_id)) {
            $lineId = $request->line_id;
            $query->whereHas('machine', function ($q) use ($lineId) {
                $q->whereIn('line_id', $lineId);
            });
        }
        $plan = $query->get()->groupBy(['due_date', 'machine_code']);
        $data = [];
        foreach ($plan as $due_date => $schedule) {
            if (!isset($data[$due_date])) {
                $data[$due_date] = [];
            }
            foreach ($schedule as $machine_code => $value) {
                $numberOfPlan = $value->count();
                $numberOfDone = $value->filter(function ($item) {
                    return $item->maintenanceLog;
                })->count();
                $numberOfRemain = $numberOfPlan - $numberOfDone;
                $machine = [
                    'machine_name' => $value[0]->machine->name ?? "",
                    'machine_code' => $machine_code,
                    'line_name' => $value[0]->machine->line->name ?? "",
                    'plan' => $numberOfPlan,
                    'done' => $numberOfDone,
                    'remain' => $numberOfRemain
                ];
                $data[$due_date][] = $machine;
            }
        }
        return $this->success($data);
    }

    public function detail(Request $request)
    {
        $query = MaintenanceSchedule::with('machine.line', 'maintenancePlan', 'maintenanceItem.maintenanceCategory', 'maintenanceLog.maintenanceLogImages');
        if(isset($request->due_date)){
            $query->whereDate('due_date', Carbon::parse($request->due_date));
        }else{
            if(isset($request->date) && count($request->date) === 2) {
                $query->whereDate('due_date', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('due_date', '<=', date('Y-m-d', strtotime($request->date[1])));;
            }else{
                $query->whereDate('due_date', now());
            }
        }
        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }
        $schedules = $query->get();
        $data = [];
        foreach ($schedules as $machine_code => $schedule) {
            $images = [];
            if ($schedule->maintenanceLog) {
                foreach ($schedule->maintenanceLog->maintenanceLogImages as $imgIndex => $image) {
                    $images[] = [
                        'uid' => $image->id,
                        'name' => 'Pic' . ($imgIndex + 1) . '.png',
                        'url' => $image->image_path,
                        'status' =>  'done'
                    ];
                }
            }
            $data[] = [
                'id' => $schedule->id,
                'machine_code' => $schedule->machine_code,
                'machine_name' => $schedule->machine_code,
                'line_name' => $schedule->machine->line->name ?? "",
                'line_id' => $schedule->machine->line->id ?? "",
                'item_name' => $schedule->maintenanceItem->name ?? "",
                'item_id' => $schedule->maintenanceItem->id ?? "",
                'category_name' => $schedule->maintenanceItem->maintenanceCategory->name ?? "",
                'category_id' => $schedule->maintenanceItem->maintenanceCategory->id ?? "",
                'planning_date' => date('d/m/Y', strtotime($schedule->due_date)),
                'log_date' => $schedule->maintenanceLog ? $schedule->maintenanceLog->log_date : null,
                'log_id' => $schedule->maintenanceLog->id ?? null,
                'images' => $images,
                'note' => $schedule->maintenanceLog ? $schedule->maintenanceLog->note : "",
                'result' => $schedule->maintenanceLog ? $schedule->maintenanceLog->result : "",
            ];
        }
        return $this->success($data);
    }

    public function cloneYear(Request $request)
    {
        $fromYear = $request->input('from_year', 2025);
        $toYear   = $request->input('to_year', 2026);

        if ($fromYear === $toYear) {
            return $this->failure(null, 'Năm nguồn và năm đích phải khác nhau.');
        }

        $schedules = MaintenanceSchedule::whereYear('due_date', $fromYear)->get();

        if ($schedules->isEmpty()) {
            return $this->failure(null, "Không tìm thấy kế hoạch nào trong năm $fromYear.");
        }

        $cloned  = 0;
        $skipped = 0;

        \DB::beginTransaction();
        try {
            foreach ($schedules as $schedule) {
                $originalDate = \Carbon\Carbon::parse($schedule->due_date);
                // Thay năm nguồn → năm đích, giữ nguyên tháng/ngày
                try {
                    $newDate = $originalDate->copy()->setYear($toYear)->format('Y-m-d');
                } catch (\Throwable $e) {
                    // Ngày không hợp lệ ở năm đích (vd 29/02 năm không nhuận)
                    $skipped++;
                    continue;
                }

                // Kiểm tra ngày có hợp lệ không (setYear có thể overflow)
                if (\Carbon\Carbon::parse($newDate)->year !== (int)$toYear) {
                    $skipped++;
                    continue;
                }

                $duplicate = MaintenanceSchedule::where('machine_code', $schedule->machine_code)
                    ->where('maintenance_item_id', $schedule->maintenance_item_id)
                    ->where('maintenance_plan_id', $schedule->maintenance_plan_id)
                    ->where('due_date', $newDate)
                    ->exists();

                if ($duplicate) {
                    $skipped++;
                    continue;
                }

                $newSchedule = MaintenanceSchedule::create([
                    'machine_code'          => $schedule->machine_code,
                    'maintenance_item_id'   => $schedule->maintenance_item_id,
                    'maintenance_plan_id'   => $schedule->maintenance_plan_id,
                    'due_date'              => $newDate,
                ]);
                $cloned++;
            }
            \DB::commit();
        } catch (\Throwable $th) {
            \DB::rollBack();
            return $this->failure($th, 'Đã xảy ra lỗi trong quá trình clone.');
        }

        return $this->success([
            'from_year' => $fromYear,
            'to_year'   => $toYear,
            'cloned'    => $cloned,
            'skipped'   => $skipped,
            'message'   => "Đã clone $cloned bản ghi từ năm $fromYear sang $toYear. Bỏ qua $skipped bản ghi (trùng lặp hoặc ngày không hợp lệ).",
        ]);
    }
}
