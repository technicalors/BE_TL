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
use Illuminate\Support\Facades\Log;

class MaintenanceScheduleImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $fields;

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
        Log::info($row);

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
                Log::debug([
                    'start_day' => $row['start_day'],
                    'cycle_type' => $row['cycle_type'],
                    'cycle_interval' => $row['cycle_interval'],
                    'status' => 'planned'
                ]);
                $maintenancePlan = MaintenancePlan::firstOrCreate([
                    'start_day' => $row['start_day'],
                    'cycle_type' => $row['cycle_type'],
                    'cycle_interval' => $row['cycle_interval'],
                    'status' => 'planned'
                ]);
                if ($maintenancePlan) {
                    //create maintenance schedule
                    $dates = $this->getMaintenanceDates(date('Y-m-') . $row['start_day'], $row['cycle_interval'], $row['cycle_type']);
                    Log::debug($dates);
                    foreach($dates as $date) {
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
        return $row;
    }

    function getMaintenanceDates($startDate, $interval, $unit)
    {
        $dates = [];
        $currentDate = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$currentDate) {
            throw new Exception("Invalid date format");
        }
        $dates[] = $currentDate->format('Y-m-d');

        // Determine the end date to ensure we stay within one year
        $endDate = (clone $currentDate)->modify('last day of December this year');

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

            // Check if the next date exceeds one year
            if ($currentDate >= $endDate) {
                break;
            }

            $dates[] = $currentDate->format('Y-m-d');
        }

        return $dates;
    }
}
