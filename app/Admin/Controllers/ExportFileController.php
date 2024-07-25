<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Shape\Chart;
use PhpOffice\PhpPresentation\Shape\Chart\Gridlines;
use PhpOffice\PhpPresentation\Shape\Chart\Legend;
use PhpOffice\PhpPresentation\Slide\Background\Image;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Title;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar3D;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie3D;
use PhpOffice\PhpPresentation\Shape\Drawing\Base64;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Outline;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;

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

        // Chất lượng công đoạn in
        $this->createQualityOverviewSlide($objPHPPowerPoint, '2. Chất lượng công đoạn in', 'Biểu đồ chất lượng công đoạn in', $slideWidth);
        // Chất lượng công đoạn ghép đế
        $this->createQualityOverviewSlide($objPHPPowerPoint, '2. Chất lượng công đoạn ghép đế', 'Biểu đồ chất lượng công đoạn ghép đế', $slideWidth);
        // Chất lượng công đoạn bế
        $this->createQualityOverviewSlide($objPHPPowerPoint, '2. Chất lượng công đoạn bế', 'Biểu đồ chất lượng công đoạn bế', $slideWidth);
        // Chất lượng công đoạn chọn
        $this->createQualityOverviewSlide($objPHPPowerPoint, '2. Chất lượng công đoạn chọn', 'Biểu đồ chất lượng công đoạn chọn', $slideWidth);
        // Chất lượng công đoạn OQC
        $this->createQualityOverviewSlide($objPHPPowerPoint, '2. Chất lượng công đoạn OQC', 'Biểu đồ chất lượng công đoạn OQC', $slideWidth);
        // Lỗi trọng điểm
        $this->loiTrongDiem($objPHPPowerPoint, $slideWidth);
        // Đối sách cải tiến
        $this->doiSachCaiTien($objPHPPowerPoint, $slideWidth);

        // Xuất file PPTX
        $oWriterPPTX = IOFactory::createWriter($objPHPPowerPoint, 'PowerPoint2007');
        $fileName = 'baocao_tuan_14.pptx';
        $filePath = storage_path($fileName);
        $oWriterPPTX->save($filePath);

        return response()->json([
            'success' => true,
            'file' => $fileName,
            'message' => 'Thực hiện thành công',
        ]);
    }

    /**
     * Slide tổng quan chất lượng công đoạn
     */
    public function createQualityOverviewSlide(PhpPresentation $objPHPPowerPoint, string $heading = 'Heading', string $chartName = 'Chart title', float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('I. TỔNG QUAN CHẤT LƯỢNG TUẦN 14');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(22)
            ->setColor(new Color('FF0000FF'));

        // Tiêu đề phụ
        $shape = $slide->createRichTextShape()
            ->setHeight(20)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50)
            ->setOffsetY(40);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun($heading);
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(18)
            ->setColor(new Color(Color::COLOR_RED));

        // Thêm biểu đồ cột
        $chartShape = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(320)
            ->setWidth(865)
            ->setOffsetX(50)
            ->setOffsetY(80);
        $chartShape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ
        $chartTitle = $chartShape->getTitle();
        $chartTitle->setText($chartName);
        $chartTitle->getFont()->setSize(12);

        $barChart = new Bar();
        $chartShape->getPlotArea()->setType($barChart);

        // Đặt vị trí của legend xuống dưới biểu đồ cột
        $chartShape->getLegend()->setPosition(Legend::POSITION_BOTTOM);

        // Thêm đường lưới ngang chính
        $gridlines = new Gridlines();
        $gridlines->getOutline()->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF707C9C'));
        $chartShape->getPlotArea()->getAxisY()->setMajorGridlines($gridlines);

        // Cấu hình trục X
        $axisX = $chartShape->getPlotArea()->getAxisX();
        $axisX->setTitle('Các tuần');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Giá trị');

        $categories = ['Tuần 08', 'Tuần 09', 'Tuần 10', 'Tuần 11', 'Tuần 12', 'Tuần 13', 'Tuần 14'];
        $values1 = [1, 5, 4, 10, 6, 2, 1]; // Số lot OK
        $values2 = [1, 1, 0, 1, 0, 0, 0]; // Số lot NG

        $series1 = new Series('Số lot OK', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF0070C0'));
        $series2 = new Series('Số lot NG', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFFF0000'));

        // $series3->setType(new Line());

        $barChart->addSeries($series1);
        $barChart->addSeries($series2);

        // Thêm bảng dữ liệu
        $tableShape = $slide->createTableShape(8);
        $tableShape->setHeight(250);
        $tableShape->setOffsetX(50);
        $tableShape->setOffsetY(405);

        // Dữ liệu bảng
        $data = [
            ['Tuần', 'Tuần 08', 'Tuần 09', 'Tuần 10', 'Tuần 11', 'Tuần 12', 'Tuần 13', 'Tuần 14'],
            ['Tổng số lot kiểm tra', 0, 5, 5, 0, 0, 2, 1],
            ['Số lot OK', 0, 5, 4, 0, 0, 2, 1],
            ['Số lot NG', 0, 0, 0, 0, 0, 0, 0],
            ['Mục tiêu', '3%', '3%', '3%', '3%', '3%', '3%', '3%'],
            ['Tỷ lệ NG (%)', '0%', '0%', '0%', '0%', '0%', '0%', '0%'],
        ];

        // Tạo bảng
        foreach ($data as $rowIdx => $rowData) {
            $row = $tableShape->createRow();
            $row->setHeight(18); // Chỉnh chiều cao của row
            foreach ($rowData as $colIdx => $cellData) {
                $cell = $row->nextCell();

                // Cài đặt độ rộng cho cột đầu tiên
                if ($colIdx == 0) {
                    $cell->setWidth(200); // Độ rộng cột đầu tiên lớn hơn
                } else {
                    $cell->setWidth(95); // Độ rộng các cột khác
                }

                $textRun = $cell->createTextRun($cellData);
                $textRun->getFont()->setName('Times New Roman')->setSize(12);
                $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Căn giữa các cell
                if ($rowIdx == 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('B7DEE8'));
                }
                if ($colIdx == 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('B7DEE8'));
                }
            }
        }
    }

    public function loiTrongDiem(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('II. LỖI TRỌNG ĐIỂM TUẦN 13 SO VỚI TUẦN 14');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(22)
            ->setColor(new Color('FF0000FF'));


        // Thêm biểu đồ cột w13
        $chartShape1 = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(320)
            ->setWidth(215)
            ->setOffsetX(50)
            ->setOffsetY(80);
        $chartShape1->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape1->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ w13
        $chartTitle1 = $chartShape1->getTitle();
        $chartTitle1->setText('Lỗi trọng điểm tuẩn 13');
        $chartTitle1->getFont()->setSize(12);
        $barChart1 = new Bar();
        $chartShape1->getPlotArea()->setType($barChart1);
        $chartShape1->getLegend()->setPosition(Legend::POSITION_BOTTOM);
        $gridlines1 = new Gridlines();
        $gridlines1->getOutline()->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF707C9C'));
        $chartShape1->getPlotArea()->getAxisY()->setMajorGridlines($gridlines1);
        $axisX = $chartShape1->getPlotArea()->getAxisX();
        $axisX->setTitle(' ');
        $axisY = $chartShape1->getPlotArea()->getAxisY();
        $axisY->setTitle(' ');
        $categories = ['BE3', 'BE2', 'BE1'];
        $values1 = [0.17, 0.41, 0.9];
        $series1 = new Series('Tuần 12', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF0070C0'));
        $barChart1->addSeries($series1);

        // Thêm biểu đồ cột w14
        $chartShape2 = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(320)
            ->setWidth(215)
            ->setOffsetX(50 + 215)
            ->setOffsetY(80);
        $chartShape2->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape2->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ w14
        $chartTitle2 = $chartShape2->getTitle();
        $chartTitle2->setText('Lỗi trọng điểm tuẩn 14');
        $chartTitle2->getFont()->setSize(12);
        $barChar2 = new Bar();
        $chartShape2->getPlotArea()->setType($barChar2);
        $chartShape2->getLegend()->setPosition(Legend::POSITION_BOTTOM);
        $gridlines2 = new Gridlines();
        $gridlines2->getOutline()->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF707C9C'));
        $chartShape2->getPlotArea()->getAxisY()->setMajorGridlines($gridlines2);
        $axisX = $chartShape2->getPlotArea()->getAxisX();
        $axisX->setTitle(' ');
        $axisY = $chartShape2->getPlotArea()->getAxisY();
        $axisY->setTitle(' ');
        $categories = ['BE3', 'BE2', 'BE1'];
        $values2 = [0.17, 0.41, 0.9];
        $series2 = new Series('Tuần 12', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF0070C0'));
        $barChar2->addSeries($series2);

        //-------------------

        // Thêm biểu đồ cột
        $chartShape = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(320)
            ->setWidth(430)
            ->setOffsetX(50 + 430)
            ->setOffsetY(80);
        $chartShape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ
        $chartTitle = $chartShape->getTitle();
        $chartTitle->setText('Biểu đồ so sánh tỷ lệ lỗi');
        $chartTitle->getFont()->setSize(12);

        $barChart = new Bar();
        $chartShape->getPlotArea()->setType($barChart);

        // Đặt vị trí của legend xuống dưới biểu đồ cột
        $chartShape->getLegend()->setPosition(Legend::POSITION_BOTTOM);

        // Thêm đường lưới ngang chính
        $gridlines = new Gridlines();
        $gridlines->getOutline()->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF707C9C'));
        $chartShape->getPlotArea()->getAxisY()->setMajorGridlines($gridlines);

        // Cấu hình trục X
        $axisX = $chartShape->getPlotArea()->getAxisX();
        $axisX->setTitle('Lỗi');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('%');

        $categories = ['BE3', 'BE2', 'BE1'];
        $values1 = [0.17, 0.41, 0.9];
        $values2 = [0.12, 0.11, 0.5];

        $series1 = new Series('Tuần 12', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_YELLOW));
        $series2 = new Series('Tuần 13', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFFF6E00'));

        $barChart->addSeries($series1);
        $barChart->addSeries($series2);

        // Description
        $shape = $slide->createRichTextShape()
            ->setHeight(50)
            ->setWidth(865);
        $shape->setOffsetY(450)->setOffsetX(50);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFEDDEBE'));
        $shape->getBorder()
            ->setLineStyle(Border::LINE_SINGLE);
        $textRun = $shape->createTextRun('Lỗi lặp lại vẫn tiếp tục diễn ra, bộ phận sản xuất cải tiến chưa triệt để, cụ thể: lỗi BE3 (Xơ, bavia) giảm từ 0.17% xuống còn 0.12%, lỗi BE2 (Dính phôi) giảm từ 0.41% xuống còn 0.12%. lỗi BE1(Bế lệch) giảm từ 0.09% xuống còn 0.07%.');
        $textRun->getFont()->setColor(new Color(Color::COLOR_BLACK));
        $shape->getActiveParagraph()
            ->getBulletStyle()
            ->setBulletType(Bullet::TYPE_BULLET)->setBulletColor(new Color(Color::COLOR_BLACK));
    }

    public function doiSachCaiTien(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('III. ĐỐI SÁCH CẢI TIẾN LỖI TRỌNG ĐIỂM TUẦN 14');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(22)
            ->setColor(new Color('FF0000FF'));

        // Thêm bảng dữ liệu
        $tableShape = $slide->createTableShape(4);
        // $tableShape->setHeight(250);
        $tableShape->setWidth(865);
        $tableShape->setOffsetX(50);
        $tableShape->setOffsetY(80);

        // Dữ liệu bảng
        $dataT = [
            ['STT', 'Thông tin', 'Nguyên nhân', 'Đối sách cải tiến'],
            [1, 'Lỗi BE2 (Hằn, dính phôi) chiếm 0.12%', '', ''],
            [2, 'Lỗi BE1 (Bế lệch) chiếm 0.07%', '', ''],
            [3, 'Lỗi BE3 (Xơ, bavia) chiếm 0.12%', '', ''],
            [4, 'Lỗi PH2(loang phủ) chiếm 5.54%', '', ''],
            [5, 'Lỗi PH3 (lệch phủ) chiếm 0.26%', '', ''],
            [6, 'Lỗi BE2 (Hằn, dính phôi) chiếm 0.12%', '', ''],
            [7, 'Lỗi BE2 (Hằn, dính phôi) chiếm 0.12%', '', ''],
        ];

        // Tạo bảng
        foreach ($dataT as $rowIdx => $rowData) {
            $row = $tableShape->createRow();
            $row->setHeight(50); // Chỉnh chiều cao của row
            foreach ($rowData as $colIdx => $cellData) {
                $cell = $row->nextCell();

                // Cài đặt độ rộng cho cột đầu tiên 865
                if ($colIdx == 0) {
                    $cell->setWidth(55); // Độ rộng cột đầu tiên lớn hơn
                } else {
                    $cell->setWidth(270); // Độ rộng các cột khác
                }
                $textRun = $cell->createTextRun($cellData);
                $textRun->getFont()->setName('Times New Roman')->setSize(12);
                $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER); // Căn giữa các cell
                if ($rowIdx == 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('B7DEE8'));
                }
                if ($colIdx == 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('B7DEE8'));
                }
            }
        }
    }
}
