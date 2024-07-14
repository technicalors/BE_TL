<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Slide\Background\Image;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie3D;
use PhpOffice\PhpPresentation\Shape\Drawing\Base64;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Fill;

class ExportFileController extends Controller
{
    public function createPPTX()
    {
        // Tạo đối tượng PhpPresentation
        $objPHPPowerPoint = new PhpPresentation();
        $color = new Color();
        //Tuỳ chỉnh layout cho slide
        $objPHPPowerPoint->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9, true);
        //Láy kích thước của slide
        $slideWidth = $objPHPPowerPoint->getLayout()->getCX(DocumentLayout::UNIT_PIXEL);
        $slideHeight = $objPHPPowerPoint->getLayout()->getCY(DocumentLayout::UNIT_PIXEL);
        // Slide 1: Tiêu đề
        $currentSlide = $objPHPPowerPoint->getActiveSlide();
        $oShape = $currentSlide->createDrawingShape();
        $oShape->setName('Unique name')
            ->setDescription('Description of the drawing')
            ->setPath(public_path('logo.png'))
            ->setWidth(180);
        $imageWidth = $oShape->getWidth();
        $offsetX = ($slideWidth - $imageWidth) / 2;
        $oShape->setOffsetX($offsetX);
        $oShape->setOffsetY(50);

        $shape = $currentSlide->createRichTextShape()
            ->setWidth(700)
            ->setHeight(40);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $textRun = $shape->createTextRun('BÁO CÁO TUẦN 14 HỆ THỐNG NHÀ MÁY THÔNG MINH');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(18)
            ->setColor(new Color(Color::COLOR_BLUE));
        $titleWidth = $shape->getWidth();
        $shape->setOffsetX(($slideWidth - $titleWidth) / 2)->setOffsetY(200);

        $shape = $currentSlide->createRichTextShape()
            ->setHeight(30)
            ->setWidth(300);

        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $textRun = $shape->createTextRun('29.03.2024-04.04.2024');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(14)
            ->setColor(new Color(Color::COLOR_BLACK));
        $titleDateWidth = $shape->getWidth();
        $shape->setOffsetX(($slideWidth - $titleDateWidth) / 2)->setOffsetY(250);

        // Slide 2: Tổng quan chất lượng tuần 14
        $slide2 = $objPHPPowerPoint->createSlide();
        $shape = $slide2->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('I. TỔNG QUAN CHẤT LƯỢNG TUẦN 14');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(22);

        // Thêm bảng vào slide 2
        $shape = $slide2->createTableShape(5);
        $shape->setHeight(600);
        $shape->setWidth($slideWidth - 50);
        $shape->setOffsetX(($slideWidth - ($slideWidth - 50)) / 2);
        $shape->setOffsetY(50);

        $row = $shape->createRow();
        $cell = $row->nextCell();
        $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_DARKGREEN));
        $cell->createTextRun('Công đoạn')->getFont()->setName('Times New Roman')->setBold(true)->setColor(new Color(Color::COLOR_WHITE));
        $cell = $row->nextCell();
        $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_DARKGREEN));
        $cell->createTextRun('Tổng số lot kiểm tra')->getFont()->setName('Times New Roman')->setBold(true)->setColor(new Color(Color::COLOR_WHITE));
        $cell = $row->nextCell();
        $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_DARKGREEN));
        $cell->createTextRun('Tổng số lot OK')->getFont()->setName('Times New Roman')->setBold(true)->setColor(new Color(Color::COLOR_WHITE));
        $cell = $row->nextCell();
        $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_DARKGREEN));
        $cell->createTextRun('Tổng số lot NG')->getFont()->setName('Times New Roman')->setBold(true)->setColor(new Color(Color::COLOR_WHITE));
        $cell = $row->nextCell();
        $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_DARKGREEN));
        $cell->createTextRun('Tỷ lệ NG (%)')->getFont()->setName('Times New Roman')->setBold(true)->setColor(new Color(Color::COLOR_WHITE));

        $data = [
            ['Gấp dán', 13, 13, 0, '0.00%'],
            ['In', 1, 1, 0, '0.00%'],
            ['Ghép đế', 10, 10, 0, '0.00%'],
            ['Bế', 11, 11, 0, '0.00%'],
            ['Chọn', 11, 11, 0, '0.00%'],
            ['OQC', 12, 12, 0, '0.00%']
        ];

        foreach ($data as $rowData) {
            $row = $shape->createRow();
            foreach ($rowData as $index => $cellData) {
                $cell = $row->nextCell();
                $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                if ($index === 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($color->setRGB('BDD7EE'));
                }
                $cell = $cell->createTextRun($cellData)->getFont()->setName('Times New Roman')->setBold(true);
            }
        }

        $shape = $slide2->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 80);
        $shape->setOffsetY(400)->setOffsetX(($slideWidth - ($slideWidth - 80)) / 2);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($color->setRGB('00B050'));
        $shape->getBorder()
            ->setLineStyle(Border::LINE_SINGLE);
        $textRun = $shape->createTextRun('Số lot kiểm tra là số sản phẩm sản xuất trong tuần (tổng số sản phẩm sản xuất trong ngày của tuần)
Số lot NG là số sản phẩm NG QC phản hồi (tính theo cột lỗi QC bắt được)');
        $textRun->getFont()->setColor(new Color(Color::COLOR_WHITE));
        $shape->getActiveParagraph()
            ->getBulletStyle()
            ->setBulletType(Bullet::TYPE_BULLET)->setBulletColor(new Color(Color::COLOR_WHITE));

        // Thêm nhiều slide và nội dung tương tự các bước trên

        // Slide 3: Biểu đồ tỷ lệ lỗi
        $slide3 = $objPHPPowerPoint->createSlide();
        $shape = $slide3->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('I. TỔNG QUAN CHẤT LƯỢNG TUẦN 14');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(22);

        $shape = $slide3->createRichTextShape()
            ->setHeight(20)
            ->setWidth($slideWidth)
            ->setOffsetY(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('1. Chất lượng công đoạn gấp dán');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setColor(new Color(Color::COLOR_RED))
            ->setSize(12);

        // Thêm biểu đồ vào slide 3
        $chartShape = $slide3->createChartShape();
        $chartShape->setHeight(300)
            ->setWidth(500)
            ->setOffsetX(($slideWidth - ($slideWidth - 500)) / 2)
            ->setOffsetY(150);
        $pie3D = new Pie3D();
        $series = new Series('Tỷ lệ lỗi', [50, 30, 20]);
        $pie3D->addSeries($series);
        $chartShape->getPlotArea()->setType($pie3D);
        $chartShape->getTitle()->setText('Biểu đồ tỷ lệ lỗi theo tuần');
        $chartShape->getLegend()->getFont()->setBold(true);
        $chartShape->getView3D()->setRotationX(20);
        $colors = [
            'FF0000', // Màu đỏ
            '00FF00', // Màu xanh lá cây
            '0000FF'  // Màu xanh dương
        ];
        Log::debug($series->getDataPointFills());
        foreach ($series->getDataPointFills() as $index => $point) {
            if (isset($colors[$index])) {
                $point->setStartColor($color->setRGB($colors[$index]))->setEndColor($color->setRGB($colors[$index]));
            }
        }

        // Xuất file PPTX
        $oWriterPPTX = IOFactory::createWriter($objPHPPowerPoint, 'PowerPoint2007');
        $fileName = 'baocao_tuan_14.pptx';
        $filePath = storage_path($fileName);
        $oWriterPPTX->save($filePath);

        return Carbon::now()->toDateTimeString();
    }
}
