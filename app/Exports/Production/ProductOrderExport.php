<?php

namespace App\Exports\Production;

use App\Models\ProductOrder;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductOrderExport implements  FromCollection, WithHeadings, WithMapping, WithStyles
{
    private $rowNumber = 0;

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return ProductOrder::with('product')->get();
    }

    public function headings(): array
    {
        return [
            'STT',
            'Số đơn hàng',
            'Mã khách hàng',
            'Mã hàng',
            'Tên hàng hoá',
            'Ngày đặt hàng',
            'Số lượng',
            'Ngày giao',
            'Ghi chú thay đổi',
        ];
    }

    public function map($record): array
    {
        $this->rowNumber++;
        return [
            $this->rowNumber,
            $record->order_number,
            $record->customer_id,
            $record->product_id,
            $record->product->name ?? '',
            $record->order_date ? Carbon::parse($record->order_date)->format('d/m/Y') : '',
            $record->quantity,
            $record->delivery_date ? Carbon::parse($record->delivery_date)->format('d/m/Y') : '',
            $record->note,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Apply styles to the header row
        $sheet->getStyle('A1:H1')->applyFromArray([
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
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->getColumnDimension('G')->setWidth(20);
        $sheet->getColumnDimension('H')->setWidth(50);
    }
}
