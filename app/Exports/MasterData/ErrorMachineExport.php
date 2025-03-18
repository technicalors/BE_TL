<?php

namespace App\Exports\MasterData;

use App\Models\ErrorMachine;
use App\Models\ProductOrder;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class ErrorMachineExport implements FromArray, WithStyles, WithEvents
{
    public function array(): array
    {
        // Headers và Slugs
        $headers = [
            'Mã lỗi',
            'Nội dung',
            'Công đoạn',
            'Loại lỗi',
            'Nguyên nhân',
            'Khắc phục',
            'Phòng ngừa',
        ];

        $slugs = [
            'code',
            'noi_dung',
            'line_name',
            'type',
            'nguyen_nhan',
            'khac_phuc',
            'phong_ngua',
        ];

        // Lấy dữ liệu từ database
        $data = ErrorMachine::with('line')->get()->map(function($record){
            return [
                $record->code,
                $record->noi_dung,
                $record->line->name ?? "",
                ErrorMachine::ERROR_TYPE[$record->type] ?? "",
                $record->nguyen_nhan,
                $record->khac_phuc,
                $record->phong_ngua,
            ];
        });
        return [
            $slugs,
            $headers,
            ...$data
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Ẩn hàng 1
                $event->sheet->getDelegate()->getRowDimension(1)->setVisible(false);
            },
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Apply styles to the header row
        $sheet->getStyle('A2:G2')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '000000'],
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF2CC'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        // Apply border style to all cells
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . $sheet->getHighestRow())->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(50);
        $sheet->getColumnDimension('F')->setWidth(50);
        $sheet->getColumnDimension('G')->setWidth(50);
    }
}
