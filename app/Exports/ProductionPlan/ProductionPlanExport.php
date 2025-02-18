<?php

namespace App\Exports\ProductionPlan;

use Maatwebsite\Excel\Concerns\FromArray;

class ProductionPlanExport implements FromArray
{
    protected $productionPlans;

    public function __construct(array $productionPlans)
    {
        $this->productionPlans = $productionPlans;
    }

    /**
     * Thêm header vào file Excel nếu cần.
     *
     * @return array
     */
    public function array(): array
    {
        // Danh sách header, bạn có thể điều chỉnh theo các trường cần xuất
        $header = [
            'product_order_id',
            'ngay_dat_hang',
            'cong_doan_sx',
            'line_id',
            'ca_sx',
            'ngay_sx',
            'ngay_giao_hang',
            'machine_id',
            'product_id',
            'product_name',
            'khach_hang',
            'so_bat',
            'sl_nvl',
            'sl_tong_don_hang',
            'sl_giao_sx',
            'sl_thanh_pham',
            'thu_tu_uu_tien',
            'note',
            'UPH',
            'nhan_luc',
            'tong_tg_thuc_hien',
            'kho_giay',
            'toc_do',
            'thoi_gian_chinh_may',
            'thoi_gian_thuc_hien',
            'thoi_gian_bat_dau',
            'thoi_gian_ket_thuc',
        ];

        return array_merge([$header], $this->productionPlans);
    }
}
