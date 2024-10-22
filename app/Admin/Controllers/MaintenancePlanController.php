<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceSchedule;
use App\Traits\API;
use Illuminate\Http\Request;

class MaintenancePlanController extends Controller
{
    use API;
    public function index(Request $request)
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

    public function list(Request $request)
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
        $schedules = $query->get()->groupBy('machine_code');
        $data = [];
        foreach ($schedules as $machine_code => $schedule) {
            $schedule->sortBy('due_date');
            $logs = $schedule->filter(function (object $item) {
                return $item->maintenanceLog;
            })->sortBy(function (object $item) {
                return $item->maintenanceLog->log_date;
            });
            $data[] = [
                'machine_code' => $machine_code,
                'machine_name' => $machine_code,
                'line_name' => $schedule[0]->machine->line->name ?? "",
                'item_number' => $schedule->count(),
                'planning_date' => date('d/m/Y', strtotime($schedule[0]->due_date)),
                'start_date' => isset($logs->first()->maintenanceLog) ? date('d/m/Y', strtotime($logs->first()->maintenanceLog->log_date)) : "",
            ];
        }
        return $this->success($data);
    }

    public function detail(Request $request)
    {
        $query = MaintenanceSchedule::with('machine.line', 'maintenancePlan', 'maintenanceItem.maintenanceCategory', 'maintenanceLog.maintenanceLogImages');
        if(isset($request->date) && count($request->date) === 2) {
            $query->whereDate('due_date', '>=', date('Y-m-d', strtotime($request->date[0])))->whereDate('due_date', '<=', date('Y-m-d', strtotime($request->date[1])));;
        }else{
            $query->whereDate('due_date', now());
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
                        'image_path' => $image->image_path,
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
                'start_date' => $schedule->maintenanceLog ? date('d/m/Y', strtotime($schedule->maintenanceLog->log_date)) : "",
                'log' => $schedule->maintenanceLog ? $schedule->maintenanceLog : "",
                'images' => $images,
                'remark' => $schedule->maintenanceLog ? $schedule->maintenanceLog->remark : "",
                'result' => $schedule->maintenanceLog ? $schedule->maintenanceLog->result : "",
            ];
        }
        return $this->success($data);
    }
}
