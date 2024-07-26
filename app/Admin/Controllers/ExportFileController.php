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
            ->setWidth(240);
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
            ->setSize(30)
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
        $shape->setOffsetX(($slideWidth - $titleDateWidth) / 2)->setOffsetY(350);

        // Slide sản xuất
        $this->sanLuong($objPHPPowerPoint, $slideWidth);
        // Slide tỷ lệ hàng OK,NG
        $this->tyLeHang($objPHPPowerPoint, $slideWidth);
        // Slide hiệu suất sử dụng
        $this->hieuSuatSuDung($objPHPPowerPoint, $slideWidth);
        // Slide dừng máy trong QT vận hành, sự cố TB
        $this->dungMayVanHanh($objPHPPowerPoint, $slideWidth);

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

        // Slide kế hoạch sản xuất
        $this->keHoachSanXuat($objPHPPowerPoint, $slideWidth);
        // Slide kết quả NXT
        $this->ketQuaNXT($objPHPPowerPoint, $slideWidth);
        // Slide tuổi tồn
        $this->tuoiTon($objPHPPowerPoint, $slideWidth);

        // Xuất file PPTX
        $oWriterPPTX = IOFactory::createWriter($objPHPPowerPoint, 'PowerPoint2007');
        $fileName = 'baocao_tuan_14.pptx';
        $filePath = public_path($fileName);
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

    /**
     * Slide sản lượng
     */
    public function sanLuong(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('SẢN XUẤT');
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
        $textRun = $shape->createTextRun('Sản lượng tháng 04');
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
        $chartTitle->setText('Biểu đồ sản lượng');
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
        $axisX->setTitle('Thiết bị');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Sản lượng');

        $categories = ['Máy in tờ rơi Komori', 'Máy phủ UV cục bộ', 'Máy bế tờ rời', 'Máy gấp hộp'];
        $values1 = [580000, 227000, 457000, 379153];
        $values2 = [420565, 190442, 354000, 234963];
        $values3 = [320565, 90442, 154000, 134963];
        $values4 = [120565, 50442, 94000, 54963];

        $series1 = new Series('Đầu vào', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF0070C0'));
        $series2 = new Series('Số lượng OK', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('00cc00'));
        $series3 = new Series('Số lượng TV', $values3, $categories);
        $series3->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ffff00'));
        $series4 = new Series('Số lượng NG', $values4, $categories);
        $series4->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ff0000'));

        // $series3->setType(new Line());

        $barChart->addSeries($series1);
        $barChart->addSeries($series2);
        $barChart->addSeries($series3);
        $barChart->addSeries($series4);

        // Description
        $shape = $slide->createRichTextShape()
            ->setHeight(90)
            ->setWidth(865);
        $shape->setOffsetY(420)->setOffsetX(50);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFEDDEBE'));
        $shape->getBorder()
            ->setLineStyle(Border::LINE_SINGLE);
        $textRun = $shape->createTextRun('Sản lượng tổng các máy tháng 4 = 65% so với tháng 3, bằng 106% so với tháng 2. Đơn hàng tháng 4 giảm, máy kết hợp chạy hàng và vệ sinh bảo dưỡng máy, điều chuyển NS sang máy khác hỗ trợ (Iwasaky, flexo) và nghỉ kế hoạch.');
        $textRun->getFont()->setColor(new Color(Color::COLOR_BLACK))->setSize(16);
        $shape->getActiveParagraph()
            ->getBulletStyle()
            ->setBulletType(Bullet::TYPE_BULLET)->setBulletColor(new Color(Color::COLOR_BLACK));
    }

    /**
     * Slide tỷ lệ hàng trong sản xuất
     */
    public function tyLeHang(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('SẢN XUẤT');
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
        $textRun = $shape->createTextRun('Tỷ lệ hàng OK, NG trong sản xuất');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(18)
            ->setColor(new Color(Color::COLOR_RED));

        // Thêm biểu đồ cột trái
        $chartShape = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(320)
            ->setWidth(430)
            ->setOffsetX(50)
            ->setOffsetY(80);
        $chartShape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ trái
        $chartTitle = $chartShape->getTitle();
        $chartTitle->setText('Tỷ lệ đạt thẳng');
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
        $axisX->setTitle('Thiết bị');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Tỷ lệ (%)');

        $categories = ['Máy in tờ rơi Komori', 'Máy phủ UV cục bộ', 'Máy bế tờ rời', 'Máy gấp hộp'];
        $values1 = [77, 55, 78, 82]; // Tháng 1/2024
        $values2 = [81, 71, 47, 52]; // Tháng 2/2024
        $values3 = [51, 58, 75, 86]; // Tháng 3/2024
        $values4 = [68, 58, 75, 69]; // Tháng 4/2024

        $series1 = new Series('Đầu vào', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF0070C0'));
        $series2 = new Series('Số lượng OK', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('00cc00'));
        $series3 = new Series('Số lượng TV', $values3, $categories);
        $series3->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ffff00'));
        $series4 = new Series('Số lượng NG', $values4, $categories);
        $series4->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ff0000'));
        $barChart->addSeries($series1);
        $barChart->addSeries($series2);
        $barChart->addSeries($series3);
        $barChart->addSeries($series4);

        // ------------------------------------

        // Thêm biểu đồ cột phải
        $chartShape = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(320)
            ->setWidth(430)
            ->setOffsetX(50 + 435)
            ->setOffsetY(80);
        $chartShape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ phải
        $chartTitle = $chartShape->getTitle();
        $chartTitle->setText('Tỷ lệ NG');
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
        $axisX->setTitle('Thiết bị');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Tỷ lệ (%)');

        $categories = ['Máy in tờ rơi Komori', 'Máy phủ UV cục bộ', 'Máy bế tờ rời', 'Máy gấp hộp', 'Trung bình'];
        $values1 = [10.3, 1.9, 4.4, 1.9];
        $values2 = [10.0, 5.5, 5.0, 2.1];
        $values3 = [9.5, 0.3, 4.3, 1.0];
        $values4 = [6.5, 3.9, 1.3, 2.1];

        $series1 = new Series('Tháng 2', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_RED));
        $series2 = new Series('Tháng 3', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(Color::COLOR_YELLOW));
        $series3 = new Series('Tháng 4', $values3, $categories);
        $series3->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF0070C0'));
        $barChart->addSeries($series1);
        $barChart->addSeries($series2);
        $barChart->addSeries($series3);

        // Description
        $shape = $slide->createRichTextShape()
            ->setHeight(90)
            ->setWidth(865);
        $shape->setOffsetY(420)->setOffsetX(50);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFEDDEBE'));
        $shape->getBorder()
            ->setLineStyle(Border::LINE_SINGLE);
        $textRun = $shape->createTextRun('Máy in KMR: tỷ lệ đạt thẳng tháng 4 (68%) giảm so với tháng trước (81%%), do có lô giấy xả về bị cong sóng (ng nhân do giấy xả bị ẩm, lô chia sử dụng lõi nhỏ, BP đã làm việc với KTCN, IQC để xử lý, khắc phục; Tỷ lệ NG tại in cao do SX số lượng nhỏ.
Máy phủ UV bị ảnh hưởng lô giấy cong >>> tỷ lệ đạt thẳng thấp (58%), giấy cong, chéo dẫn đến vào lệch, phủ lệch; đã yêu cầu có xác nhận của quản lý và QC trước khi SX, gửi cho tke tổng hợp.');
        $textRun->getFont()->setColor(new Color(Color::COLOR_BLACK))->setSize(12);
        $shape->getActiveParagraph()
            ->getBulletStyle()
            ->setBulletType(Bullet::TYPE_BULLET)->setBulletColor(new Color(Color::COLOR_BLACK));
    }

    /**
     * Slide hiệu suất sử dụng
     */
    public function hieuSuatSuDung(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('SẢN XUẤT');
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
        $textRun = $shape->createTextRun('Hiệu suất sử dụng máy');
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
        $chartTitle->setText(' ');
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
        $axisX->setTitle('Thiết bị');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Tỷ lệ (%)');

        $categories = ['Máy in tờ rơi Komori', 'Máy phủ UV cục bộ', 'Máy bế tờ rời', 'Máy gấp hộp', 'Hiệu suất TB/tháng'];
        $values1 = [81, 45, 72, 86, 70]; // Tháng 1/2024
        $values2 = [70, 69, 74, 74, 79]; // Tháng 2/2024
        $values3 = [65, 55, 71, 79, 82]; // Tháng 3/2024
        $values4 = [90, 40, 75, 79, 64]; // Tháng 4/2024

        $series1 = new Series('Tháng 1', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('33cc33'));
        $series2 = new Series('Tháng 2', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ffff00'));
        $series3 = new Series('Tháng 3', $values3, $categories);
        $series3->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('0066ff'));
        $series4 = new Series('Tháng 4', $values4, $categories);
        $series4->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ff6600'));
        $barChart->addSeries($series1);
        $barChart->addSeries($series2);
        $barChart->addSeries($series3);
        $barChart->addSeries($series4);

        // Description
        $shape = $slide->createRichTextShape()
            ->setHeight(90)
            ->setWidth(865);
        $shape->setOffsetY(420)->setOffsetX(50);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFEDDEBE'));
        $shape->getBorder()
            ->setLineStyle(Border::LINE_SINGLE);
        $textRun = $shape->createTextRun('Hiệu suất máy trung bình tháng 4 đạt 64%, chưa đạt mục tiêu (75%), hiệu suất máy phủ đạt thấp do thời gian vào hàng lâu, giấy cong, vào lệch, dừng máy nhiều để kiểm tra.
BPSX đã đưa p.án khắc phục giấy cong : IQC đã làm việc với NCC về quản lý độ ẩm giấy, tổ chia dùng lõi to để chia cuộn.
So sánh : Hiệu suất chung thấp hơn 10% so với tháng 3/2024.');
        $textRun->getFont()->setColor(new Color(Color::COLOR_BLACK))->setSize(12);
        $shape->getActiveParagraph()
            ->getBulletStyle()
            ->setBulletType(Bullet::TYPE_BULLET)->setBulletColor(new Color(Color::COLOR_BLACK));
    }

    /**
     * Slide dừng máy trong QT vận hành, sự cố TB
     */
    public function dungMayVanHanh(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('SẢN XUẤT');
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
        $textRun = $shape->createTextRun('Dừng máy trong QT vận hành, sự cố TB');
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
        $chartTitle->setText(' ');
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
        $axisX->setTitle('Thiết bị');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Tỷ lệ (%)');

        $categories = ['Máy in tờ rơi Komori', 'Máy phủ UV cục bộ', 'Máy bế tờ rời', 'Máy gấp hộp', 'Tổng cộng'];
        $values1 = [373, 59, 343, 0, 775]; // Tháng 1/2024
        $values2 = [408, 42, 200, 14, 667]; // Tháng 2/2024
        $values3 = [408, 96, 3, 30, 734]; // Tháng 3/2024
        $values4 = [478, 101, 215, 3, 597]; // Tháng 4/2024

        $series1 = new Series('Tháng 1', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('33cc33'));
        $series2 = new Series('Tháng 2', $values2, $categories);
        $series2->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ffff00'));
        $series3 = new Series('Tháng 3', $values3, $categories);
        $series3->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('0066ff'));
        $series4 = new Series('Tháng 4', $values4, $categories);
        $series4->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('ff6600'));
        $barChart->addSeries($series1);
        $barChart->addSeries($series2);
        $barChart->addSeries($series3);
        $barChart->addSeries($series4);

        // Description
        $shape = $slide->createRichTextShape()
            ->setHeight(90)
            ->setWidth(865);
        $shape->setOffsetY(420)->setOffsetX(50);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFEDDEBE'));
        $shape->getBorder()
            ->setLineStyle(Border::LINE_SINGLE);
        $textRun = $shape->createTextRun('Tổng số lần dừng máy : 597, thấp hơn các tháng trước, một phần do sản xuất ít hàng hơn.
Các lỗi dừng máy chủ yếu liên quan đến vận hành, dừng chủ động, dừng do lệch giấy, chỉnh màu, chỉnh bản, thay bản in, lau cao su, lau bản in, kiểm tra sp, ...
Máy in, bế phát sinh dừng máy nhiều, BP đang tìm p.án cải tiến');
        $textRun->getFont()->setColor(new Color(Color::COLOR_BLACK))->setSize(12);
        $shape->getActiveParagraph()
            ->getBulletStyle()
            ->setBulletType(Bullet::TYPE_BULLET)->setBulletColor(new Color(Color::COLOR_BLACK));
    }

    /**
     * Slide ke hoach san xuat
     */
    public function keHoachSanXuat(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('V. KẾ HOẠCH SẢN XUẤT');
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
        $textRun = $shape->createTextRun('1. Kế hoạch sản xuất - kho');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(18)
            ->setColor(new Color(Color::COLOR_RED));

        // Thêm bảng dữ liệu
        $tableShape = $slide->createTableShape(4);
        // $tableShape->setHeight(250);
        $tableShape->setWidth(865);
        $tableShape->setOffsetX(50);
        $tableShape->setOffsetY(80);

        // Dữ liệu bảng
        $dataT = [
            ['STT', 'Hạng mục báo cáo', 'Kế quả thực hiện và theo dõi', 'Vấn đề đang cải tiến'],
            [1, 'Cập nhật danh mục NVL cấp cho tổ SX hàng ngày', 'Đang thực hiện duy trì hàng tháng thay cho QT cũ', ''],
            [2, 'Cập nhật kế hoạch sản xuất lên PM hàng ngày', 'Đang thực hiện duy trì hàng tháng thay cho QT cũ', ''],
            [3, 'Thực hiện X-N-T hàng ngày', 'Thực hiện hàng ngày:
Tổng thời gian thực hiện X-N và cập nhật lệnh trên máy là từ ngày 01/04-30/4:  140 phút/ tổng173thùng
Trung bình thực hiện: 0.809 phút/ thùng', ''],
            [4, 'Kiểm tra và cập nhật KQSX hàng ngày', 'Đang thực hiện duy trì hàng tháng thay cho QT cũ', ''],
            [5, '', '', ''],
            [6, '', '', ''],
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
                $cell->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER); // Căn giữa các cell
                if ($rowIdx == 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('B7DEE8'));
                }
                if ($colIdx == 0) {
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('B7DEE8'));
                }
            }
        }
    }

    /**
     * Slide kết quả nhập - xuất - tồn kho
     */
    public function ketQuaNXT(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('V. KẾ HOẠCH SẢN XUẤT');
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
        $textRun = $shape->createTextRun('Kết quả nhập - xuất - tồn tháng 04');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(18)
            ->setColor(new Color(Color::COLOR_RED));

        // Thêm biểu đồ cột
        $chartShape = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(400)
            ->setWidth(865)
            ->setOffsetX(50)
            ->setOffsetY(80);
        $chartShape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape->getBorder()->setColor(new Color('FF000000'));

        // Thêm tiêu đề cho biểu đồ
        $chartTitle = $chartShape->getTitle();
        $chartTitle->setText('Kết quả nhập - xuất - tồn kho');
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
        $axisX->setTitle('NHẬP - XUẤT - TỒN');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Giá trị');

        $categories = ['Nhập', 'Xuất', 'Tồn'];
        $values1 = [200000, 150000, 50000,];

        $series1 = new Series('Giá trị', $values1, $categories);
        $series1->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor((new Color())->setRGB('0066ff'));
        $barChart->addSeries($series1);
    }

    /**
     * Slide tuổi tồn
     */
    public function tuoiTon(PhpPresentation $objPHPPowerPoint, float $slideWidth)
    {
        $slide = $objPHPPowerPoint->createSlide();

        // Tiêu đề chính
        $shape = $slide->createRichTextShape()
            ->setHeight(40)
            ->setWidth($slideWidth - 100)
            ->setOffsetX(50);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_JUSTIFY);
        $textRun = $shape->createTextRun('V. KẾ HOẠCH SẢN XUẤT');
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
        $textRun = $shape->createTextRun('Tuổi tồn của hàng hóa trong kho');
        $textRun->getFont()
            ->setName('Times New Roman')
            ->setBold(true)
            ->setSize(18)
            ->setColor(new Color(Color::COLOR_RED));

        // Thêm bảng dữ liệu
        $tableShape = $slide->createTableShape(3);
        // $tableShape->setHeight(250);
        $tableShape->setWidth(300);
        $tableShape->setOffsetX(50);
        $tableShape->setOffsetY(80);

        // Dữ liệu bảng
        $data = [
            ['Số ngày tồn', 'Số mã tồn', 'Số lượng tồn'],
            [5, 13, 20800],
            [7, 1, 1000],
            [10, 10, 9620],
            [11, 4, 6000],
            [16, 2, 4000],
            [17, 3, 3000],
            [22, 3, 4500],
            [26, 1, 1500],
            [34, 1, 698],
            [43, 1, 20],
            [49, 4, 3500],
            [51, 2, 6300],
            [54, 1, 2200],
            [55, 1, 700],
            [62, 2, 3000],
            [69, 1, 900],
            [71, 25, 16550],
            [99, 2, 3000],
            [104, 12, 8400],
            [105, 7, 4500],
            [106, 5, 4500],
        ];

        // Tạo bảng
        foreach ($data as $rowIdx => $rowData) {
            $row = $tableShape->createRow();
            $row->setHeight(15); // Chỉnh chiều cao của row
            foreach ($rowData as $colIdx => $cellData) {
                $cell = $row->nextCell();
                $cell->setWidth(100);
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

        // Thêm biểu đồ cột
        $chartShape = $slide->createChartShape()
            ->setResizeProportional(false)
            ->setHeight(400)
            ->setWidth(500)
            ->setOffsetX(50 + 300)
            ->setOffsetY(80);
        $chartShape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        $chartShape->getBorder()->setColor(new Color('FF000000'));

        $barChart = new Bar();
        $chartShape->getPlotArea()->setType($barChart);

        // Thêm đường lưới ngang chính
        $gridlines = new Gridlines();
        $gridlines->getOutline()->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF707C9C'));
        $chartShape->getPlotArea()->getAxisY()->setMajorGridlines($gridlines);

        // Cấu hình trục X
        $axisX = $chartShape->getPlotArea()->getAxisX();
        $axisX->setTitle('Ngày');

        // Cấu hình trục Y
        $axisY = $chartShape->getPlotArea()->getAxisY();
        $axisY->setTitle('Giá trị');

        $categories = range(1, 31); // Ngày trong tháng
        $values1 = [20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200, 210, 220, 230, 240, 250, 260, 270, 280, 290, 300, 310, 320]; // Số ngày tồn
        $values2 = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95, 100, 105, 110, 115, 120, 125, 130, 135, 140, 145, 150, 155]; // Số mã tồn

        $series1 = new Series('Số ngày tồn', $values1, $categories);
        $series2 = new Series('Số mã tồn', $values2, $categories);
        $barChart->addSeries($series1);
        $barChart->addSeries($series2);

        // Đặt vị trí của legend
        $chartShape->getLegend()->setPosition(Legend::POSITION_BOTTOM);
    }
}
