<?php

namespace App\Imports;

use App\Models\Lot;
use App\Models\Stamp;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class StampsImport implements ToModel, WithHeadingRow, WithStartRow
{
    public function headingRow(): int
    {
        return 1;
    }

    public function startRow(): int
    {
        return 4;
    }

    public function model(array $row)
    {
        return new Stamp([
            'lot_id' => $row['lot_id'],
            'ten_sp' => $row['ten_sp'],
            'soluongtp' => $row['soluongtp'],
            'ver' => $row['ver'],
            'his' => $row['his'],
            'lsx' => $row['lsx'],
            'cd_thuc_hien' => $row['cd_thuc_hien'] ?? 'Trước bảo ôn',
            'cd_tiep_theo' => $row['cd_tiep_theo'] ?? 'OQC',
            'nguoi_sx' => $row['nguoi_sx'],
            'ghi_chu' => $row['ghi_chu'],
        ]);
    }
}
