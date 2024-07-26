<?php

namespace App\Imports;

use App\Models\MaintenanceCategory;
use App\Models\MaintenanceItem;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceSchedule;
use DateTime;
use Exception;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaintenanceScheduleImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $fields;
    protected $request;

    public function __construct($request = [])
    {
        $this->request = $request;
    }

    // Hàm này xác định hàng bắt đầu lấy tiêu đề (heading row)
    public function headingRow(): int
    {
        return 2;
    }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 5;
    }
    public function collection(Collection $collection)
    {
        $this->fields = $collection->toArray(); // Lấy tên trường từ hàng thứ 2

        foreach ($collection as $row) {
            $this->importRow($row->toArray());
        }
    }

    protected function importRow(array $row)
    {
        try {
            DB::beginTransaction();
            //create maintanance category
            $maintenanceCategory = MaintenanceCategory::firstOrCreate(['name' => $row['maintenance_category_name']]);
            if ($maintenanceCategory) {
                //create maintenance item
                $maintenanceItem = MaintenanceItem::firstOrCreate([
                    'maintenance_category_id' => $maintenanceCategory->id,
                    'name' => $row['maintenance_item_name'],
                ]);
                if ($maintenanceItem) {
                    //create maintenance plan
                    $maintenancePlan = MaintenancePlan::firstOrCreate([
                        'start_day' => $row['start_day'],
                        'cycle_type' => $row['cycle_type'],
                        'cycle_interval' => $row['cycle_interval'],
                    ]);
                    if ($maintenancePlan) {
                        //create maintenance schedule
                        $start_date = date('Y-01-01');
                        $end_date = date('Y-12-31');
                        if (isset($this->request['start_date'])) {
                            $start_date = date('Y-m-d', strtotime($this->request['start_date']));
                        }
                        if (isset($this->request['end_date'])) {
                            $end_date = date('Y-m-d', strtotime($this->request['end_date']));
                        }
                        $dates = $this->getMaintenanceDates($start_date, $end_date, $row['cycle_interval'], $row['cycle_type'], $row['start_day']);
                        foreach ($dates as $date) {
                            $maintenanceSchedule = MaintenanceSchedule::create([
                                'maintenance_item_id' => $maintenanceItem->id,
                                'maintenance_plan_id' => $maintenancePlan->id,
                                'machine_code' => $row['machine_code'],
                                'due_date' => $date,
                            ]);
                        }
                    }
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
        return $row;
    }

    function getMaintenanceDates($startDate, $endDate, $interval, $unit, $dateNumber)
    {
        $dates = [];
        $dateObj = DateTime::createFromFormat('Y-m-d', $startDate);
        // Lấy tháng và năm từ đối tượng DateTime
        $month = $dateObj->format('m');
        $year = $dateObj->format('Y');

        // Tạo ngày mới với tháng và năm cũ, nhưng thay ngày bằng số ngày mới
        $currentDate = DateTime::createFromFormat('Y-m-d', "$year-$month-$dateNumber");
        if (!$currentDate) {
            throw new Exception("Invalid date format");
        }
        $dates[] = $currentDate->format('Y-m-d');
        while (true) {
            switch (strtolower($unit)) {
                case 'ngày':
                    $currentDate->modify("+$interval days");
                    break;
                case 'tuần':
                    $currentDate->modify("+$interval weeks");
                    break;
                case 'tháng':
                    $currentDate->modify("+$interval months");
                    break;
                case 'năm':
                    $currentDate->modify("+$interval years");
                    break;
                default:
                    throw new Exception("Invalid time unit");
            }
            if ($currentDate->format('Y-m-d') >= $endDate) {
                break;
            }
            $dates[] = $currentDate->format('Y-m-d');
        }
        return $dates;
    }
}
