<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpPresentation\Style\Fill;

class ExcelStyleHelper
{
    public static function alignment($position = 'center')
    {
        switch ($position) {
            case 'center':
                $vertical = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
                $horizontal = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
                break;
            case 'right':
                $vertical = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
                $horizontal = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT;
                break;
            default:
                $vertical = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
                $horizontal = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT;
                break;
        }
        return [
            'alignment' => [
                'vertical' => $vertical,
                'horizontal' => $horizontal
            ]
        ];
    }

    public static function borders($style = 'allBorders', $color = '000000')
    {
        return [
            'borders' => array(
                $style => array(
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => array('argb' => $color),
                ),
            ),
        ];
    }
    public static function bold($isBold = true, $size = 0)
    {
        return [
            'font' => array_merge(['bold' => $isBold], $size ? ['size' => $size] : [])
        ];
    }
    public static function fill($color = 'BFBFBF')
    {
        return [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => array('argb' => $color)
            ]
        ];
    }
}
