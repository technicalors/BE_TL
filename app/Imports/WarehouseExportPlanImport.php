<?php

namespace App\Imports;

use App\Models\FcPlant;
use App\Models\FcPlantDetail;
use App\Models\Product;
use App\Models\WareHouseExportPlan;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithSheetName;

class WarehouseExportPlanImport implements ToCollection, WithStartRow, WithCalculatedFormulas//, WithMultipleSheets
{
    protected int $imported = 0;

    // public function headingRow(): int
    // {
    //     return 1;
    // }

    // public function sheets(): array
    // {
    //     return [
    //         1 => $this,
    //     ];
    // }

    // Hàm này xác định hàng bắt đầu lấy dữ liệu (data row)
    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $cellDate = $this->extractDateFromString2($rows->first()->toArray()[0]);
        if (empty($cellDate)) throw new Exception('Không lấy được ngày xuất hàng');

        $khachHangPrev = null;
        $cuaGiaoHangPrev = null;

        $data = [];
        foreach ($rows->toArray() as $key => $row) {
            if ($key > 2) {
                $product_id = $row[2] ?? null;
                $ten_san_pham = $row[3] ?? null;
                $sl_yeu_cau_giao = $row[4] ?? null;
                $dvt = $row[5] ?? null;
                $tong_kg = $row[6] ?? null;
                $ton_kho = $row[7] ?? null;
                $xac_nhan_sx = $row[8] ?? null;
                $sl_thuc_xuat = $row[9] ?? null;
                $quy_cach = $row[10] ?? null;
                $ghi_chu = $row[12] ?? null;

                $khach_hang = null;
                if ($row[1] == null || $row[1] == '') {
                    $khach_hang = $khachHangPrev;
                } else {
                    $khach_hang = $row[1];
                    $khachHangPrev = $row[1];
                }

                $cua_xuat_hang = null;
                if ($row[11] == null || $row[11] == '') {
                    $cua_xuat_hang = $cuaGiaoHangPrev;
                } else {
                    $cua_xuat_hang = $row[11];
                    $cuaGiaoHangPrev = $row[11];
                }

                if (!isset($product_id) || !isset($ten_san_pham) || !isset($sl_yeu_cau_giao) || !isset($dvt)) continue;
                $product_id = trim($product_id);

                $product = Product::find($product_id);
                // $product = Product::firstOrCreate(['id' => $product_id, 'name' => $ten_san_pham]);
                if (empty($product)) throw new Exception("Mã hàng hóa '{$product_id}' không tồn tại!");

                $data[] = [
                    'ngay_xuat_hang' => $cellDate,
                    'khach_hang' => $khach_hang,
                    'product_id' => $product_id,
                    'ten_san_pham' => $ten_san_pham,
                    'sl_yeu_cau_giao' => $sl_yeu_cau_giao,
                    'dvt' => $dvt,
                    'tong_kg' => $tong_kg,
                    'ton_kho' => $ton_kho,
                    'xac_nhan_sx' => $xac_nhan_sx,
                    'sl_thuc_xuat' => $sl_thuc_xuat,
                    'quy_cach' => $quy_cach,
                    'cua_xuat_hang' => $cua_xuat_hang,
                    'ghi_chu' => $ghi_chu,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $this->imported++;
            }
        }
        if ($this->imported == 0) throw new Exception('Không có bản ghi nào được thêm');
        WareHouseExportPlan::insert($data);                
    }

    // Hàm trích xuất ngày tháng năm từ chuỗi
    private function extractDateFromString($rawDate)
    {
        preg_match('/Ngày (\d{2}) Tháng (\d{2}) Năm (\d{4})/', $rawDate, $matches);

        if (isset($matches[1], $matches[2], $matches[3])) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return null;
    }

    private function extractDateFromString2($rawDate)
    {
        $dateString = preg_replace('/\s+/', '', $rawDate);
        preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $dateString, $matches);

        if (isset($matches[1], $matches[2], $matches[3])) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return null;
    }
}
