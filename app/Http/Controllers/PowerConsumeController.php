<?php

namespace App\Http\Controllers;

use App\Helpers\ExcelStyleHelper;
use App\Models\DailyPowerConsume;
use App\Models\PowerConsume;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PowerConsumeController extends Controller
{
    use API;

    public function monthlyConsumption(Request $request)
    {
        $query = PowerConsume::select(
            DB::raw('DATE(`date`) as date'),
            DB::raw('SUM(`end_value` - `start_value`) as total_consumption')
        )
            ->groupBy(DB::raw('DATE(`date`)'))
            ->orderBy('date', 'asc');

        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }

        if (isset($request->datetime)) {
            $month = Carbon::parse($request->datetime)->format('m');
            $year = Carbon::parse($request->datetime)->format('Y');
            $query->whereMonth('date', $month)->whereYear('date', $year);
        }

        $result = $query->get();

        return $this->success($result);
    }

    public function dailyConsumption(Request $request)
    {
        // $query = DailyPowerConsume::select(
        //     'machine_code',
        //     DB::raw('DATE(`date`) as date'),
        //     DB::raw('HOUR(`date`) as hour'),
        //     DB::raw('SUM(`end_value` - `start_value`) as total_consumption')
        // )
        //     ->groupBy('machine_code', DB::raw('DATE(`date`)'), DB::raw('HOUR(`date`)'))
        //     ->orderBy('date', 'asc')
        //     ->orderBy('hour', 'asc');
        $query = DailyPowerConsume::query()->orderBy('created_at');

        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }

        if (isset($request->datetime)) {
            $month = Carbon::parse($request->datetime)->format('m');
            $year = Carbon::parse($request->datetime)->format('Y');
            $query->whereMonth('date', $month)->whereYear('date', $year);
        }

        $result = $query->get();

        return $this->success($result);
    }

    public function exportMonthlyConsumption(Request $request)
    {
        $query = PowerConsume::select(
            DB::raw('DATE(`date`) as date'),
            DB::raw('SUM(`end_value` - `start_value`) as total_consumption')
        )
            ->groupBy(DB::raw('DATE(`date`)'))
            ->orderBy('date', 'asc');

        if (isset($request->machine_code)) {
            $query->where('machine_code', $request->machine_code);
        }

        $month = null;
        $year  = null;
        if (isset($request->datetime)) {
            $parsed = Carbon::parse($request->datetime);
            $month  = $parsed->format('m');
            $year   = $parsed->format('Y');
            $query->whereMonth('date', $month)->whereYear('date', $year);
        }

        // Build map ngày -> tổng tiêu thụ từ DB
        $recordMap = [];
        foreach ($query->get() as $row) {
            $recordMap[$row->date] = round((float)$row->total_consumption, 2);
        }

        // Fill đủ ngày trong tháng, ngày không có data = 0
        $daysInMonth = ($month && $year)
            ? Carbon::createFromDate($year, $month, 1)->daysInMonth
            : Carbon::now()->daysInMonth;
        $allDays = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr   = sprintf('%04d-%02d-%02d', $year ?? Carbon::now()->year, $month ?? Carbon::now()->month, $d);
            $allDays[] = [
                'date'              => $dateStr,
                'total_consumption' => $recordMap[$dateStr] ?? 0,
            ];
        }

        // Build Excel
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $titleStyle  = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(true, 14));
        $headerStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(), ExcelStyleHelper::fill());
        $border      = ExcelStyleHelper::borders();
        $centerStyle = ExcelStyleHelper::alignment();

        // Title (row 1)
        $machineLabel = $request->machine_code ? ' - Máy: ' . $request->machine_code : '';
        $monthLabel   = ($month && $year) ? ' Tháng ' . $month . '/' . $year : '';
        $sheet->setCellValue([1, 1], 'Báo cáo điện năng tiêu thụ theo tháng' . $monthLabel . $machineLabel)
              ->mergeCells([1, 1, 3, 1])
              ->getStyle([1, 1, 3, 1])
              ->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Header (row 2)
        $headers = ['STT', 'Ngày', 'Tổng điện năng tiêu thụ (kWh)'];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue([$col + 1, 2], $label)
                  ->getStyle([$col + 1, 2])
                  ->applyFromArray($headerStyle);
        }
        $sheet->getRowDimension(2)->setRowHeight(22);

        // Data rows (starting row 3) — đủ mọi ngày trong tháng
        $totalConsumption = 0;
        foreach ($allDays as $index => $row) {
            $rowNum = $index + 3;
            $value  = $row['total_consumption'];
            $totalConsumption += $value;
            $sheet->setCellValue([1, $rowNum], $index + 1);
            $sheet->setCellValue([2, $rowNum], Carbon::parse($row['date'])->format('d/m/Y'));
            $sheet->setCellValue([3, $rowNum], $value);
            $sheet->getStyle([1, $rowNum, 3, $rowNum])
                  ->applyFromArray(array_merge($centerStyle, $border));
        }

        // Total row
        $totalRow = count($allDays) + 3;
        $totalRowStyle = array_merge(ExcelStyleHelper::alignment(), ExcelStyleHelper::bold(), $border);
        $sheet->setCellValue([1, $totalRow], 'Tổng')
              ->mergeCells([1, $totalRow, 2, $totalRow]);
        $sheet->setCellValue([3, $totalRow], round($totalConsumption, 2));
        $sheet->getStyle([1, $totalRow, 3, $totalRow])->applyFromArray($totalRowStyle);

        // Auto-size columns + apply border to header
        foreach ([1, 2, 3] as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->getStyle([1, 2, 3, 2])->applyFromArray(array_merge($headerStyle, $border));

        // Chart: biểu đồ cột bên phải bảng (cột E–N)
        $dataStartRow = 3;
        $dataEndRow   = $totalRow - 1;
        $dataCount    = count($allDays);

        if ($dataCount > 0) {
            $sheetTitle = $sheet->getTitle();

            $xLabels = new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_STRING,
                $sheetTitle . '!$B$' . $dataStartRow . ':$B$' . $dataEndRow,
                null,
                $dataCount
            );

            $dataSeriesLabel = new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_STRING,
                $sheetTitle . '!$C$2',
                null,
                1
            );

            $lineSeriesLabel = new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                'Xu hướng',
                1
            );

            $yValues = new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $sheetTitle . '!$C$' . $dataStartRow . ':$C$' . $dataEndRow,
                null,
                $dataCount
            );

            $series = new \PhpOffice\PhpSpreadsheet\Chart\DataSeries(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::TYPE_BARCHART,
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::GROUPING_CLUSTERED,
                range(0, 0),
                [$dataSeriesLabel],
                [$xLabels],
                [$yValues]
            );
            $series->setPlotDirection(\PhpOffice\PhpSpreadsheet\Chart\DataSeries::DIRECTION_COL);

            // Thêm line series để có dây
            $lineSeries = new \PhpOffice\PhpSpreadsheet\Chart\DataSeries(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::TYPE_LINECHART,
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::GROUPING_STANDARD,
                range(0, 1),
                [$lineSeriesLabel],
                [$xLabels],
                [$yValues]
            );
            $lineSeries->setPlotDirection(\PhpOffice\PhpSpreadsheet\Chart\DataSeries::DIRECTION_COL);

            $plotArea = new \PhpOffice\PhpSpreadsheet\Chart\PlotArea(null, [$series, $lineSeries]);
            $legend   = new \PhpOffice\PhpSpreadsheet\Chart\Legend(
                \PhpOffice\PhpSpreadsheet\Chart\Legend::POSITION_BOTTOM,
                null,
                false
            );
            $chartTitle = new \PhpOffice\PhpSpreadsheet\Chart\Title('Điện năng tiêu thụ theo ngày (kWh)');

            $chart = new \PhpOffice\PhpSpreadsheet\Chart\Chart(
                'chart_power_monthly',
                $chartTitle,
                $legend,
                $plotArea,
                true,
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::EMPTY_AS_GAP,
                null,
                null
            );

            // Đặt biểu đồ bên phải bảng (cột E = col 5)
            $chartTopRow = 2;   // ngang hàng với header
            $chartBotRow = $totalRow + 1;
            $chart->setTopLeftPosition('E' . $chartTopRow);
            $chart->setBottomRightPosition('N' . $chartBotRow);

            $sheet->addChart($chart);
        }

        // Save & return path
        if (!is_dir(public_path('exported_files'))) {
            mkdir(public_path('exported_files'), 0755, true);
        }
        $filename = 'Điện năng tiêu thụ theo tháng.xlsx';
        $filepath = public_path('exported_files/' . $filename);
        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($filepath);

        // Post-process: set dashed line cho chart
        try {
            $zip = new \ZipArchive();
            if ($zip->open($filepath) === true) {
                $chartEntry = null;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (strpos($name, 'charts/chart') !== false && strpos($name, '.xml') !== false) {
                        $chartEntry = $name;
                        break;
                    }
                }

                if ($chartEntry) {
                    $chartXml = $zip->getFromName($chartEntry);
                    // Tìm line series (dòng 2 trong series) và thêm dashed style
                    // Pattern: thêm <a:ln> với <a:prstDash> vào line series
                    $chartXml = preg_replace_callback(
                        '/<c:ser><c:idx val="1"\/><c:order.*?<\/c:ser>/s',
                        function ($m) {
                            $ser = $m[0];
                            // Thêm line style nếu chưa có
                            if (!strpos($ser, '<a:prstDash')) {
                                $ser = preg_replace(
                                    '/<\/c:ser>/',
                                    '<a:ln><a:prstDash val="dash"/><a:round/></a:ln></c:ser>',
                                    $ser
                                );
                            }
                            return $ser;
                        },
                        $chartXml
                    );
                    $zip->addFromString($chartEntry, $chartXml);
                }
                $zip->close();
            }
        } catch (\Throwable $e) {
            // Nếu post-process fail, file vẫn valid nhưng không có dashed line
            \Log::debug('Chart dashed line post-process failed: ' . $e->getMessage());
        }

        return $this->success('/exported_files/' . rawurlencode($filename));
    }
}
