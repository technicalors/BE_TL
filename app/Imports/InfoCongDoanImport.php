<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\ErrorMachine;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\ProductionPlan;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Str;

class InfoCongDoanImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $fields;
    protected $maxId = 0;
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
        $firstRow = $collection->first();
        if (empty($firstRow['lo_sx'])) throw new Exception('Không tìm thấy lo_sx');
        $this->maxId = $this->generateNewId($firstRow['lo_sx'] . '.L.');
        // $this->fields = $collection->toArray();
        foreach ($collection as $row) {
            $this->importRow($row->toArray());
        }
    }

    protected function importRow(array $row)
    {
        $cong_doan_sx = $row['cong_doan_sx'] ?? null;
        $lotsize = $row['lotsize'] ?? 1000;
        $lo_sx = $row['lo_sx'] ?? null;
        $line_id = $row['line_id'] ?? null;
        $product_id = $row['product_id'] ?? null;
        $thoi_gian_bat_dau = $row['thoi_gian_bat_dau'] ?? null;
        $thoi_gian_bam_may = $row['thoi_gian_bam_may'] ?? null;
        $thoi_gian_ket_thuc = $row['thoi_gian_ket_thuc'] ?? null;
        $machine_id = $row['machine_id'] ?? null;
        $sl_kh = $row['sl_kh'] ?? 0;
        $ngay_dat_hang = $row['ngay_dat_hang'] ?? null;
        $cong_doan_sx = $row['cong_doan_sx'] ?? null;
        $ca_sx = $row['ca_sx'] ?? null;
        $ngay_sx = $row['ngay_sx'] ?? null;
        $ngay_giao_hang = $row['ngay_giao_hang'] ?? date('Y-m-d');
        $khach_hang = $row['khach_hang'] ?? null;
        $so_bat = $row['so_bat'] ?? null;
        $sl_nvl = $row['sl_nvl'] ?? 0;
        $sl_tong_don_hang = $row['sl_tong_don_hang'] ?? 0;
        $sl_giao_sx = $row['sl_giao_sx'] ?? 0;
        $sl_thanh_pham = $row['sl_thanh_pham'] ?? 0;
        $thu_tu_uu_tien = $row['thu_tu_uu_tien'] ?? 1;
        $note = $row['note'] ?? null;
        $status = $row['status'] ?? 0;

        if (empty($lo_sx) || empty($cong_doan_sx) || empty($machine_id) || empty($khach_hang)) return;
        $line = Line::query()->whereRaw('UPPER(name) LIKE ?', [strtoupper(trim($cong_doan_sx))])->first();
        if (empty($line)) throw new Exception('Không tìm thấy công đoạn');

        $customer = Customer::query()->whereRaw('UPPER(name) LIKE ?', [strtoupper(trim($khach_hang))])->first();
        if (empty($customer)) throw new Exception('Không tìm thấy khách hàng');

        // Info cong doan
        $info = InfoCongDoan::create([
            'lot_id' => $this->maxId,
            'lotsize' => $lotsize,
            'lo_sx' => $lo_sx,
            'line_id' => $line->id,
            'product_id' => $product_id,
            'thoi_gian_bat_dau' => empty($thoi_gian_bat_dau) ? null : $this->transformDate($thoi_gian_bat_dau),
            'thoi_gian_bam_may' => empty($thoi_gian_bam_may) ? null : $this->transformDate($thoi_gian_bam_may),
            'thoi_gian_ket_thuc' => empty($thoi_gian_ket_thuc) ? null : $this->transformDate($thoi_gian_ket_thuc),
            // 'sl_dau_vao_chay_thu' => $sl_dau_vao_chay_thu,
            // 'sl_dau_ra_chay_thu' => $sl_dau_ra_chay_thu,
            // 'sl_dau_vao_hang_loat' => $sl_dau_vao_hang_loat,
            // 'sl_dau_ra_hang_loat' => $sl_dau_ra_hang_loat,
            // 'sl_tem_vang' => $sl_tem_vang,
            // 'sl_ng' => $sl_ng,
            // 'start_powerM' => $start_powerM,
            // 'end_powerM' => $end_powerM,
            // 'powerM' => $powerM,
            // 'updated_at' => $updated_at,
            'status' => $status,
            // 'machine_code' => $machine_code,
            'sl_kh' => $sl_kh,
            'user_id' => auth()->user()->id,
        ]);
        if (empty($info)) throw new Exception('Tạo mới Info công đoạn thất bại');

        // Production plan
        $productionPlan = ProductionPlan::query()->where([
            ['line_id', $line->id], ['lo_sx', $lo_sx], ['product_id', $product_id]
        ])->first();

        if (empty($productionPlan)) {
            $productionPlan = ProductionPlan::create([
                'line_id' => $line->id,
                'ngay_dat_hang' => $ngay_dat_hang,
                'cong_doan_sx' => $cong_doan_sx,
                'ca_sx' => $ca_sx,
                'ngay_sx' => $ngay_sx,
                'ngay_giao_hang' => $this->transformDate($ngay_giao_hang),
                'machine_id' => $machine_id,
                'product_id' => $product_id,
                'khach_hang' => $customer->id,
                'lo_sx' => $lo_sx,
                'so_bat' => $so_bat,
                'sl_nvl' => $sl_nvl,
                'sl_thanh_pham' => $sl_thanh_pham,
                'thu_tu_uu_tien' => $thu_tu_uu_tien,
                'note' => $note,
                // 'UPH' => $UPH,
                // 'nhan_luc' => $nhan_luc,
                // 'tong_tg_thuc_hien' => $tong_tg_thuc_hien,
                'thoi_gian_bat_dau' => empty($thoi_gian_bat_dau) ? null : $this->transformDate($thoi_gian_bat_dau),
                'thoi_gian_ket_thuc' => empty($thoi_gian_bat_dau) ? null : $this->transformDate($thoi_gian_bat_dau),
                // 'so_may_can_sx' => $so_may_can_sx,
                // 'file' => $file,
                // 'nvl_da_cap' => $nvl_da_cap,
                'status' => $status,
                // 'kho_giay' => $kho_giay,
                // 'toc_do' => $toc_do,
                // 'thoi_gian_chinh_may' => $thoi_gian_chinh_may,
                // 'thoi_gian_thuc_hien' => $thoi_gian_thuc_hien,
                'sl_giao_sx' => $sl_giao_sx,
                'sl_tong_don_hang' => $info->lotsize,
            ]);
            if (empty($productionPlan)) throw new Exception('Tạo mới KHSX thất bại');
        } else {
            $productionPlan->sl_tong_don_hang += $info->lotsize;
            $productionPlan->save();
        }

        //
        $this->maxId++;
        Log::debug($this->maxId);
    }

    protected function transformDate($value)
    {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
    }

    protected function generateNewId($prefix)
    {
        $latestRecord = InfoCongDoan::query()
            ->where('id', 'like', "$prefix%")
            ->orderBy('id', 'desc')
            ->first();

        if (!$latestRecord) {
            $newId = 1;
        } else {
            $latestId = $latestRecord->id;
            $latestNumber = intval(substr($latestId, strlen($prefix)));
            // $newNumber = str_pad($latestNumber + 1, 4, '0', STR_PAD_LEFT);
            $newId = $latestNumber + 1;
        }
        return $newId;
    }
}
