<?php

namespace App\Services;

use App\Models\InfoCongDoan;
use App\Models\Losx;
use App\Models\Machine;
use App\Models\ProductionPlan;
use App\Models\Tracking;
use Illuminate\Support\Str;

class WorkshopA2ProductionDashboardService
{
    public const FACTORY_ID = 2;

    public const STAGES = [
        [
            'key' => 'gap_dan',
            'title' => 'Gấp dán liên hoàn',
            'line_ids' => [24],
            'patterns' => ['gap dan lien hoan', 'gap dan', 'gấp dán'],
        ],
        [
            'key' => 'in_flexo',
            'title' => 'In Flexo',
            'line_ids' => [25],
            'patterns' => ['in flexo', 'flexo'],
        ],
        [
            'key' => 'dan_tem',
            'title' => 'Dán Tem',
            'line_ids' => [31],
            'patterns' => ['dan tem', 'dán tem'],
        ],
        [
            'key' => 'dan_liner',
            'title' => 'Dán Liner',
            'line_ids' => [27],
            'patterns' => ['dan liner', 'dán liner'],
        ],
        [
            'key' => 'duc_cat',
            'title' => 'Đục Cắt',
            'line_ids' => [26],
            'patterns' => ['duc cat', 'đục cắt'],
        ],
        [
            'key' => 'chon',
            'title' => 'Chọn',
            'line_ids' => [29],
            'patterns' => ['chon', 'chọn'],
        ],
    ];

    public const METRICS = [
        ['key' => 'running_machines', 'label' => 'Máy chạy'],
        ['key' => 'completed_plan_machines', 'label' => 'Máy hoàn thành kế hoạch'],
        ['key' => 'stopped_machines', 'label' => 'Máy dừng'],
        ['key' => 'total_plan_qty', 'label' => 'KH SX'],
        ['key' => 'remain_qty', 'label' => 'Số cần sx'],
        ['key' => 'total_actual_qty', 'label' => 'Tổng sản lượng đạt'],
        ['key' => 'completion_rate', 'label' => 'Tỷ lệ hoàn thành', 'is_percent' => true],
        ['key' => 'ng_rate', 'label' => 'Tỷ lệ NG', 'is_percent' => true],
    ];

    public function build(?string $orderingMachine = null): array
    {
        $machineRows = $this->collectMachineRows($orderingMachine);
        $stageSummaries = $this->aggregateByStage($machineRows);
        $overallSummary = $this->summarizeRows($machineRows);

        return [
            'title' => 'TÌNH HÌNH SẢN XUẤT XƯỞNG A2',
            'stage_section_title' => 'TÌNH HÌNH SẢN XUẤT CÁC CÔNG ĐOẠN A2',
            'summary' => $overallSummary,
            'stages' => $this->buildStagesPayload($stageSummaries),
            'stage_table' => $this->buildStageTable($stageSummaries),
            'legend' => [
                ['title' => 'Máy dừng', 'color' => 'orange', 'status' => 1],
                ['title' => 'Máy đang chạy', 'color' => 'green', 'status' => 3],
                ['title' => 'Máy hoàn thành kế hoạch', 'color' => 'blue', 'status' => 2],
            ],
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    protected function collectMachineRows(?string $orderingMachine = null): array
    {
        $lineIds = collect(self::STAGES)->pluck('line_ids')->flatten()->unique()->values()->all();
        $query = Machine::with('line')
            ->whereIn('line_id', $lineIds);

        if (!empty($orderingMachine)) {
            $order = array_filter(explode(',', $orderingMachine));
            if (!empty($order)) {
                $firstElement = array_shift($order);
                $order[] = $firstElement;
                $orderByString = "'" . implode("','", $order) . "'";
                $query->orderByRaw("FIELD(code, {$orderByString})");
            }
        } else {
            $query->orderBy('name');
        }

        $machines = $query->get();
        $data = [];

        foreach ($machines as $machine) {
            $row = $this->buildMachineRow($machine);
            if ($row !== null) {
                $data[] = $row;
            }
        }

        return $data;
    }

    protected function buildMachineRow(Machine $machine): ?array
    {
        $info = InfoCongDoan::where('line_id', $machine->line_id)
            ->where('machine_code', $machine->code)
            ->whereDate('thoi_gian_bat_dau', date('Y-m-d'))
            ->orderBy('thoi_gian_bat_dau', 'DESC')
            ->first();

        $tracking = Tracking::where('machine_id', $machine->code)->first();
        $lineName = mb_strtoupper($machine->line->name ?? '', 'UTF-8');

        if (!$info) {
            $plan = ProductionPlan::where('line_id', $machine->line_id)
                ->where('machine_id', $machine->code)
                ->whereDate('thoi_gian_bat_dau', date('Y-m-d'))
                ->first();

            $losx = Losx::find($plan->lo_sx ?? '');
            $product = $losx->product ?? null;

            return [
                'line_id' => (int) $machine->line_id,
                'is_iot' => (int) ($machine->is_iot ?? 0),
                'cong_doan' => $lineName,
                'machine_code' => $machine->code,
                'machine_name' => $machine->code,
                'product' => $product->name ?? '',
                'sl_dau_ra_kh' => (float) ($plan->sl_giao_sx ?? 0),
                'sl_thuc_te' => 0,
                'sl_muc_tieu' => (float) ($plan->sl_giao_sx ?? 0),
                'ti_le_ng' => 0,
                'ti_le_ht' => 0,
                'status' => 0,
                'stage_key' => $this->resolveStageKey((int) $machine->line_id, $lineName),
            ];
        }

        $plan = ProductionPlan::find($info->plan_id);
        if (!$plan) {
            return null;
        }

        $sumLotPlan = (float) ($plan->sl_giao_sx ?? 0);
        $sumInfoActure = (int) InfoCongDoan::where('plan_id', $plan->id)
            ->whereDate('thoi_gian_bat_dau', date('Y-m-d'))
            ->sum('sl_dau_ra_hang_loat');

        $tlHt = $sumLotPlan > 0
            ? (int) number_format(($sumInfoActure / $sumLotPlan) * 100, 2)
            : 0;

        $status = (int) $machine->is_iot === 1
            ? $this->resolveIotMachineStatus($info, $tracking, $tlHt)
            : $this->resolveNonIotMachineStatus($info, $tlHt);

        if ($status === 0) {
            return null;
        }

        $losx = Losx::find($plan->lo_sx ?? '');
        $product = $losx->product ?? null;
        $tiLeNg = $info->sl_dau_ra_hang_loat > 0
            ? (100 * (float) number_format($info->sl_ng / $info->sl_dau_ra_hang_loat, 2))
            : 0;

        $tiLeHt = $sumLotPlan > 0
            ? (int) (100 * (float) number_format($sumInfoActure / $sumLotPlan, 2))
            : 0;

        if ($tiLeHt > 100) {
            $tiLeHt = 100;
        }

        $congDoan = mb_strtoupper($info->line->name ?? $lineName, 'UTF-8');

        return [
            'line_id' => (int) $machine->line_id,
            'is_iot' => (int) ($machine->is_iot ?? 0),
            'cong_doan' => $congDoan,
            'machine_code' => $machine->code,
            'machine_name' => $machine->code,
            'product' => $product->name ?? '',
            'sl_dau_ra_kh' => $sumLotPlan,
            'sl_thuc_te' => (float) $sumInfoActure,
            'sl_muc_tieu' => $sumLotPlan,
            'ti_le_ng' => $tiLeNg,
            'ti_le_ht' => $tiLeHt,
            'status' => $status,
            'stage_key' => $this->resolveStageKey((int) $machine->line_id, $congDoan),
        ];
    }

    protected function resolveIotMachineStatus($info, ?Tracking $tracking, int $tlHt): int
    {
        $status = 0;
        $trackingStatus = $tracking->status ?? null;

        if (
            (!is_null($info->thoi_gian_bat_dau) && is_null($info->thoi_gian_bam_may) && is_null($info->thoi_gian_ket_thuc))
            || ($tracking && ($trackingStatus != 1) && $tlHt < 95)
        ) {
            $status = 1;
        }

        if (
            !is_null($info->thoi_gian_bat_dau)
            && !is_null($info->thoi_gian_bam_may)
            && is_null($info->thoi_gian_ket_thuc)
            && $tracking
            && $trackingStatus == 1
        ) {
            $status = 3;
        }

        if ($tlHt > 95) {
            $status = 2;
        }

        return $status;
    }

    protected function resolveNonIotMachineStatus($info, int $tlHt): int
    {
        if ($tlHt > 95 || !is_null($info->thoi_gian_ket_thuc)) {
            return 2;
        }

        if (
            !is_null($info->thoi_gian_bat_dau)
            && !is_null($info->thoi_gian_bam_may)
            && is_null($info->thoi_gian_ket_thuc)
        ) {
            return 3;
        }

        if (
            !is_null($info->thoi_gian_bat_dau)
            && is_null($info->thoi_gian_bam_may)
            && is_null($info->thoi_gian_ket_thuc)
        ) {
            return 1;
        }

        if (!is_null($info->thoi_gian_bat_dau) && $tlHt < 95) {
            return 1;
        }

        return 0;
    }

    protected function resolveStageKey(int $lineId, string $congDoan = ''): ?string
    {
        foreach (self::STAGES as $stage) {
            if (in_array($lineId, $stage['line_ids'], true)) {
                return $stage['key'];
            }
        }

        $normalized = $this->normalizeText($congDoan);
        if ($normalized === '') {
            return null;
        }

        foreach (self::STAGES as $stage) {
            foreach ($stage['patterns'] as $pattern) {
                if (Str::contains($normalized, $this->normalizeText($pattern))) {
                    return $stage['key'];
                }
            }
        }

        return null;
    }

    protected function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    protected function aggregateByStage(array $machineRows): array
    {
        $grouped = [];
        foreach (self::STAGES as $stage) {
            $grouped[$stage['key']] = [];
        }

        foreach ($machineRows as $row) {
            $stageKey = $row['stage_key'] ?? $this->resolveStageKey(
                (int) ($row['line_id'] ?? 0),
                (string) ($row['cong_doan'] ?? '')
            );

            if ($stageKey && array_key_exists($stageKey, $grouped)) {
                $grouped[$stageKey][] = $row;
            }
        }

        $summaries = [];
        foreach (self::STAGES as $stage) {
            $summaries[$stage['key']] = $this->summarizeRows($grouped[$stage['key']]);
        }

        return $summaries;
    }

    protected function summarizeRows(array $rows): array
    {
        $summary = [
            'running_machines' => 0,
            'completed_plan_machines' => 0,
            'stopped_machines' => 0,
            'total_plan_qty' => 0,
            'total_actual_qty' => 0,
            'total_ng_qty' => 0,
        ];

        foreach ($rows as $row) {
            $planQty = (float) ($row['sl_dau_ra_kh'] ?? 0);
            $actualQty = (float) ($row['sl_thuc_te'] ?? 0);
            $ngRate = (float) ($row['ti_le_ng'] ?? 0);
            $rowStatus = (int) ($row['status'] ?? 0);
            $rowNgQty = $actualQty > 0 ? ($actualQty * $ngRate) / 100 : 0;

            if ($rowStatus === 3) {
                $summary['running_machines']++;
            }
            if ($rowStatus === 1) {
                $summary['stopped_machines']++;
            }
            if ($rowStatus === 2) {
                $summary['completed_plan_machines']++;
            }

            $summary['total_plan_qty'] += $planQty;
            $summary['total_actual_qty'] += $actualQty;
            $summary['total_ng_qty'] += $rowNgQty;
        }

        $summary['remain_qty'] = max($summary['total_plan_qty'] - $summary['total_actual_qty'], 0);
        $summary['completion_rate'] = $summary['total_plan_qty'] > 0
            ? round(($summary['total_actual_qty'] / $summary['total_plan_qty']) * 100, 1)
            : 0;
        $summary['ng_rate'] = $summary['total_actual_qty'] > 0
            ? round(($summary['total_ng_qty'] / $summary['total_actual_qty']) * 100, 1)
            : 0;

        return $summary;
    }

    protected function buildStagesPayload(array $stageSummaries): array
    {
        return array_map(function (array $stage) use ($stageSummaries) {
            return [
                'key' => $stage['key'],
                'title' => $stage['title'],
                'summary' => $stageSummaries[$stage['key']] ?? $this->summarizeRows([]),
            ];
        }, self::STAGES);
    }

    protected function buildStageTable(array $stageSummaries): array
    {
        $table = [];

        foreach (self::METRICS as $metric) {
            $row = [
                'metric_key' => $metric['key'],
                'metric' => $metric['label'],
            ];

            foreach (self::STAGES as $stage) {
                $summary = $stageSummaries[$stage['key']] ?? $this->summarizeRows([]);
                $row[$stage['key']] = $summary[$metric['key']] ?? 0;
            }

            $table[] = $row;
        }

        return $table;
    }
}
