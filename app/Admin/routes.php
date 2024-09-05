<?php

use App\Admin\Controllers\ApiMobileController;
use App\Admin\Controllers\ApiUIController;
use App\Admin\Controllers\CheckSheetApiController;
use App\Admin\Controllers\ErrorMachineApiController;
use App\Admin\Controllers\ExportFileController;
use App\Admin\Controllers\InfoCongDoanController;
use App\Admin\Controllers\IOTController;
use App\Admin\Controllers\KPIController;
use App\Admin\Controllers\MachineController;
// use App\Admin\Controllers\ProductionPlanController;
use App\Admin\Controllers\RoleController;
use App\Admin\Controllers\MaintenanceCategoryController;
use App\Admin\Controllers\MaintenanceItemController;
use App\Admin\Controllers\MaintenanceLogController;
use App\Admin\Controllers\MaintenancePlanController;
use App\Admin\Controllers\MaintenanceScheduleController;
use App\Admin\Controllers\MaintenanceLogImageController;
use App\Admin\Controllers\Phase2DBApiController;
use App\Admin\Controllers\Phase2OIApiController;
use App\Admin\Controllers\Phase2UIApiController;
use App\Admin\Controllers\ParameterController;
use App\Admin\Controllers\StampController;
use App\Models\MaintenancePlan;
use Encore\Admin\Facades\Admin;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Admin::routes();

Route::group([
    'prefix'        => config('admin.route.prefix'),
    'namespace'     => config('admin.route.namespace'),
    'middleware'    => config('admin.route.middleware'),
    'as'            => config('admin.route.prefix') . '.',
], function (Router $router) {

    $router->get('/', 'HomeController@index')->name('home');
    $router->resource('/production_plan', ProductionPlanController::class);
    $router->post('/production_plan/import', [App\Admin\Controllers\ProductionPlanController::class, 'import']);
    $router->resource('/test_criteria', TestCriteriaController::class);
    $router->resource('/error_machine', ErrorMachineController::class);
    $router->post('/test_criteria/import', [App\Admin\Controllers\TestCriteriaController::class, 'importTestCriteria']);
    $router->resource('/error', ErrorController::class);
    $router->post('/error/import', [App\Admin\Controllers\ErrorController::class, 'import']);

    $router->resource('/warehouse', WareHouseController::class);
    $router->post('/warehouse/import', [App\Admin\Controllers\WareHouseController::class, 'import']);


    $router->post('/warehouse/import_ink', [App\Admin\Controllers\WareHouseController::class, 'import_ink']);
    $router->resource('/machine', MachineController::class);
    $router->post('/machine/import', [App\Admin\Controllers\MachineController::class, 'importMachine']);

    $router->resource('/workers', WorkerController::class);
    $router->post('/workers/import', [App\Admin\Controllers\WorkerController::class, 'import']);
    // //Import product
    // $router->resource('/products', ProductController::class);
    // $router->post('/products/import', [App\Admin\Controllers\ProductController::class, 'import']);
    // $router->post('/products/import_pro', [App\Admin\Controllers\ProductController::class, 'import_pro']);


    //Line
    $router->resource('lines', LineController::class);


    //Errors
    $router->resource('errors', ErrorController::class);

    //Admin-

    $router->resource('custom-users', CustomAdminController::class);

    //Time-set --
    $router->resource('time-sets', TimesetController::class);

    //Customer
    $router->resource('customers', CustomerController::class);

    //Material
    $router->resource('materials', MaterialController::class);

    //Product

    $router->resource('products', ProductController::class);
    $router->post('/products/import', [App\Admin\Controllers\ProductController::class, 'import']);

    //Check Sheet

    $router->resource('check-sheets', CheckSheetController::class);
    $router->post('/check-sheets/import', [App\Admin\Controllers\CheckSheetController::class, 'import']);

    //LineTable

    $router->resource('line-tables', LineTableController::class);

    //Error Machine

    $router->resource('error-machines', ErrorMachineController::class);
    $router->post('/error-machines/import', [App\Admin\Controllers\ErrorMachineController::class, 'import']);

    //Scenario
    $router->resource('scenarios', ScenarioController::class);

    //Category

    $router->resource('categories', CategoryController::class);
});


//API
Route::group([
    'prefix'        => "/api",
    'middleware'    => [],
    'as'            => "/api" . '.',
], function (Router $router) {

    $router->post('/login', [ApiMobileController::class, 'login']);
    $router->any('/webhook', [ApiMobileController::class, 'webhook']);
    $router->any('/power', [ApiMobileController::class, 'power']);
    $router->any('/frequency', [ApiMobileController::class, 'frequency']);
    $router->get('/webhook/log', [ApiMobileController::class, 'webhook_history']);

    $router->get('/recall', [ApiMobileController::class, 'recallIOT']);
    $router->any('/tinh_san_luong', [ApiMobileController::class, 'tinhSanLuongIOT']);
    $router->get('/thu_nghiem', [ApiMobileController::class, 'thuNghiemIOT']);
    $router->get('/chatluong', [ApiMobileController::class, 'thongsoIOT']);
});

// UI-API
Route::group([
    'prefix'        => "/api",
    'middleware'    => [],
    'as'            => "/api" . '.',
], function (Router $router) {
    $router->get('/produce/history', [ApiUIController::class, 'produceHistory']);
    $router->get('/produce/fmb', [ApiUIController::class, 'fmb']);
    $router->get('/qc/history', [ApiUIController::class, 'qcHistory']);
    $router->get('/qc/detail-data-error', [ApiUIController::class, 'getDetailDataError']);

    $router->get('/machine/error', [ApiUIController::class, 'machineError']);

    $router->get('/warning/alert', [ApiUIController::class, 'getAlert']);
    $router->get('/machine/perfomance', [ApiUIController::class, 'apimachinePerfomance']);

    $router->get('/kpi', [ApiUIController::class, 'apiKPI']);

    $router->get('/oqc', [ApiUIController::class, 'oqc']);

    $router->get('/dashboard/monitor', [ApiMobileController::class, 'dashboardMonitor']);
    $router->post('/dashboard/insert-monitor', [ApiMobileController::class, 'insertMonitor']);
    $router->get('/dashboard/get-monitor', [ApiMobileController::class, 'getMonitor']);
    $router->get('/dashboard/get-troubleshoot-monitor', [ApiMobileController::class, 'getMonitorTroubleshoot']);

    $router->get('/inventory', [ApiUIController::class, 'inventory']);
});


// END UI-API;




Route::group([
    'prefix'        => "/api",
    'middleware'    => "auth:sanctum",
    'as'            => "mobile/api" . '.',
], function (Router $router) {

    $router->post('line/check-sheet-log/save', [ApiMobileController::class, 'lineChecksheetLogSave']);
    // USER
    $router->get('/user/info', [ApiMobileController::class, 'userInfo']);
    $router->get('/user/logout', [ApiMobileController::class, 'logout']);
    $router->post('/user/password/update', [ApiMobileController::class, 'userChangePassword']);


    // LINE
    $router->get('/line/list', [ApiMobileController::class, 'listLine']);
    $router->get('/line/list-machine', [ApiMobileController::class, 'listMachineOfLine']);

    $router->get('/scenario/list', [ApiMobileController::class, 'listScenario']);
    $router->post('/scenario/update', [ApiMobileController::class, 'updateScenario']);
    //
    $router->get('/warehouse/propose-import', [ApiMobileController::class, 'getProposeImport']);
    $router->post('/warehouse/import', [ApiMobileController::class, 'importWareHouse']);
    $router->get('/warehouse/list-import', [ApiMobileController::class, 'listImportWareHouse']);
    $router->get('/warehouse/info-import', [ApiMobileController::class, 'infoImportWareHouse']);
    $router->get('/warehouse/list-customer', [ApiMobileController::class, 'listCustomerExport']);
    $router->get('/warehouse/propose-export', [ApiMobileController::class, 'getProposeExport']);
    $router->post('/warehouse/export', [ApiMobileController::class, 'exportWareHouse']);
    $router->get('/warehouse/info-export', [ApiMobileController::class, 'infoExportWareHouse']);
    $router->get('/material/list-log', [ApiMobileController::class, 'listLogMaterial']);
    $router->post('/material/update-log', [ApiMobileController::class, 'updateLogMaterial']);
    $router->post('/material/update-log-record', [ApiMobileController::class, 'updateLogMaterialRecord']);
    $router->post('/material/store-log', [ApiMobileController::class, 'storeLogMaterial']);
    $router->get('/material/list-lsx', [ApiMobileController::class, 'listLsxUseMaterial']);
    $router->post('/barrel/split', [ApiMobileController::class, 'splitBarrel']);
    $router->get('/warehouse/history', [ApiMobileController::class, 'getHistoryWareHouse']);
    $router->delete('/warehouse-export/destroy', [ApiMobileController::class, 'destroyWareHouseExport']);
    $router->post('/warehouse-export/update', [ApiMobileController::class, 'updateWareHouseExport']);
    $router->post('/warehouse-export/create', [ApiMobileController::class, 'createWareHouseExport']);
    $router->get('/warehouse-export/get-thung', [ApiMobileController::class, 'prepareGT']);
    $router->post('/warehouse-export/gop-thung', [ApiMobileController::class, 'gopThungIntem']);

    //PLAN PRODUCTION

    $router->get('/plan/detail', [ApiMobileController::class, 'planDetail']);
    $router->get('/plan/list/machine', [ApiMobileController::class, 'planMachineDetail']);
    $router->get('/plan/lsx/list', [ApiMobileController::class, 'lsxList']);
    $router->get('/plan/lsx/detail', [ApiMobileController::class, 'lsxDetail']);
    $router->post('/plan/lsx/update', [ApiMobileController::class, 'lsxUpdate']);
    $router->get('/plan/lsx/log', [ApiMobileController::class, 'lsxLog']);
    $router->delete('product_plan/destroy', [ApiMobileController::class, 'destroyProductPlan']);
    $router->post('product_plan/store', [ApiMobileController::class, 'storeProductPlan']);
    $router->post('product_plan/update', [ApiMobileController::class, 'updateProductPlan']);

    $router->post('/plan/lsx/test', [ApiMobileController::class, 'lsxTest']);


    //Machine
    $router->get('/machine/list', [ApiMobileController::class, 'listMachine']);
    $router->get('/machine/detail', [ApiMobileController::class, 'detailMachine']);

    //Warehouse
    $router->get('/warehouse/product/detail', [ApiMobileController::class, 'productDetail']);
    $router->get('/warehouse/detail', [ApiMobileController::class, 'warehouseDetail']);
    $router->get('/warehouse/log', [ApiMobileController::class, 'warehouseLog']);

    $router->post('/warehouse/cell_product/update', [ApiMobileController::class, 'cellProductUpdate']);
    $router->get('/warehouse/material', [ApiMobileController::class, 'material']);
    $router->get('/warehouse/list', [ApiMobileController::class, 'warehouseList']);

    $router->get('/warehouse/cell/empty', [ApiMobileController::class, 'cellEmpty']);



    // Meterial

    $router->get('/material/list', [ApiMobileController::class, 'materialList']);
    $router->get('/material/detail', [ApiMobileController::class, 'materialDetail']);
    $router->post('/material/create', [ApiMobileController::class, 'materialCreate']);

    $router->get('/material/log', [ApiMobileController::class, 'materialLog']);


    //Color
    $router->get('/color/list', [ApiMobileController::class, 'colorList']);


    //Unusual
    $router->get('/machine/log', [ApiMobileController::class, 'machineLog']);
    $router->get('/reason/list', [ApiMobileController::class, 'reasonList']);
    $router->post('/machine/log/update', [ApiMobileController::class, 'machineLogUpdate']);
    $router->get('/machine/reason/list', [ApiMobileController::class, 'machineReasonList']);


    //UIUX

    $router->get('/ui/plan', [ApiMobileController::class, 'uiPlan']);
    // ui-MAIN
    $router->get('/ui/lines', [ApiMobileController::class, 'ui_getLines']);
    $router->get('/ui/lines/tree', [ApiMobileController::class, 'getTreeLines']);
    $router->get('/ui/line/list-machine', [ApiMobileController::class, 'ui_getLineListMachine']);
    $router->get('/ui/machines', [ApiMobileController::class, 'ui_getMachines']);
    $router->get('/ui/customers', [ApiMobileController::class, 'ui_getCustomers']);
    $router->get('/ui/products', [ApiMobileController::class, 'ui_getProducts']);
    $router->get('/ui/staffs', [ApiMobileController::class, 'ui_getStaffs']);
    $router->get('/ui/lo-san-xuat', [ApiMobileController::class, 'ui_getLoSanXuat']);
    $router->get('/ui/warehouses', [ApiMobileController::class, 'ui_getWarehouses']);
    $router->get('/ui/ca-san-xuat-s', [ApiMobileController::class, 'ui_getCaSanXuats']);
    $router->get('/ui/errors', [ApiMobileController::class, 'ui_getErrors']);
    $router->get('/ui/errors-machine', [ApiMobileController::class, 'ui_getErrorsMachine']);

    $router->get('/ui/thong-so-may', [ApiMobileController::class, 'uiThongSoMay']);


    // Test Criteria
    $router->get('/testcriteria/list', [ApiMobileController::class, 'testCriteriaList']);
    $router->post('/testcriteria/result', [ApiMobileController::class, 'testCriteriaResult']);
    $router->get('/error/list', [ApiMobileController::class, 'errorList']);
    $router->get('/testcriteria/lsx/choose', [ApiMobileController::class, 'testCriteriaChooseLSX']);
    $router->get('/testcriteria/history', [ApiMobileController::class, 'testCriteriaHistory']);
    $router->get('/machine/info', [ApiMobileController::class, 'getInfoMachine']);


    $router->get('ui/manufacturing', [ApiMobileController::class, 'uiManufacturing']);
    $router->get('ui/quality', [ApiMobileController::class, 'uiQuality']);


    //MATERIAL

    //LOT /PALLET

    $router->get('lot/list', [ApiMobileController::class, 'palletList']);
    $router->delete('pallet/destroy', [ApiMobileController::class, 'destroyPallet']);
    $router->post('lot/update-san-luong', [ApiMobileController::class, 'updateSanLuong']);
    $router->get('lot/check-san-luong', [ApiMobileController::class, 'checkSanLuong']);
    $router->post('lot/bat-dau-tinh-dan-luong', [ApiMobileController::class, 'batDauTinhSanLuong']);
    $router->get('lot/detail', [ApiMobileController::class, 'detailLot']);

    // Production-Process

    $router->post('lot/scanPallet', [ApiMobileController::class, 'scanPallet']);

    $router->get('lot/info', [ApiMobileController::class, 'infoPallet']);

    $router->post('lot/input', [ApiMobileController::class, 'inputPallet']);
    $router->get('line/overall', [ApiMobileController::class, 'lineOverall']);
    $router->get('line/user', [ApiMobileController::class, 'lineUser']);
    $router->post('line/assign', [ApiMobileController::class, 'lineAssign']);
    $router->get('line/table/list', [ApiMobileController::class, 'listTable']);
    $router->post('line/table/work', [ApiMobileController::class, 'lineTableWork']);

    $router->post('lot/intem', [ApiMobileController::class, 'inTem']);



    //QC
    $router->post('qc/scanPallet', [ApiMobileController::class, 'scanPalletQC']);

    $router->get('qc/test/list', [ApiMobileController::class, 'testList']);
    $router->get('qc/error/list', [ApiMobileController::class, 'errorList']);

    $router->post('qc/test/result', [ApiMobileController::class, 'resultTest']);
    $router->post('qc/error/result', [ApiMobileController::class, 'errorTest']);
    $router->get('qc/overall', [ApiMobileController::class, 'qcOverall']);
    $router->get('iqc/overall', [ApiMobileController::class, 'iqcOverall']);

    $router->post('qc/update-temvang', [ApiMobileController::class, 'updateSoLuongTemVang']);
    $router->post('qc/intemvang', [ApiMobileController::class, 'inTemVang']);
    $router->get('qc/pallet/info', [ApiMobileController::class, 'infoQCPallet']);
    $router->get('qc/losx/detail', [ApiMobileController::class, 'detailLoSX']);

    //DASHBOARD


    $router->get('dashboard/giam-sat', [ApiMobileController::class, 'dashboardGiamSat']);
    $router->get('dashboard/giam-sat-chat-luong', [ApiMobileController::class, 'dashboardGiamSatChatLuong']);

    $router->get('dashboard/status', [ApiMobileController::class, 'dashboardKhiNen']);

    $router->get('dashboard/sensor', [ApiMobileController::class, 'dashboardSensor']);

    //Parameters
    $router->get('machine/parameters', [App\Admin\Controllers\ApiMobileController::class, 'getMachineParameters']);
    $router->post('machine/parameters/update', [App\Admin\Controllers\ApiMobileController::class, 'updateMachineParameters']);

    $router->get('lot/table-data-chon', [ApiMobileController::class, 'getTableAssignData']);


    $router->post('machine/machine-log/save', [ApiMobileController::class, 'logsMachine_save']);
    $router->post('update/test', [ApiMobileController::class, 'updateWarehouseEportPlan']);

    //Monitor 
    $router->get('/monitor/history', [ApiMobileController::class, 'historyMonitor']);
    $router->post('/monitor/update', [ApiMobileController::class, 'updateMonitor']);

    $router->get('/info/chon', [ApiMobileController::class, 'infoChon']);

    $router->get('/iot/status', [ApiMobileController::class, 'statusIOT']);
    $router->get('/list-product', [ApiMobileController::class, 'listProduct']);
    $router->post('/tao-tem', [ApiMobileController::class, 'taoTem']);
});



Route::group([
    'prefix'        => "/api",
    'middleware'    => "auth:sanctum",
    'as'            => "mobile/api" . '.',
], function (Router $router) {
    $router->post('/machine/update', [ApiMobileController::class, 'exMachineUpdate']);
    $router->get('/material/barcode', [ApiMobileController::class, 'inNhanMuc']);
    $router->get('/tem/print', [ApiMobileController::class, 'temPrint']);
    $router->get('/location/barcode', [ApiMobileController::class, 'locationBarcode']);

    $router->get('/product/barcode', [ApiMobileController::class, 'productBarcode']);
    $router->post('/upload-ke-hoach-xuat-kho-tong', [ApiMobileController::class, 'uploadKHXKT']);
    $router->post('/upload-ke-hoach-san-xuat', [ApiMobileController::class, 'uploadKHSX']);
    $router->post('/lot/store', [ApiMobileController::class, 'storeLot']);
    $router->get('lot/list-table', [ApiMobileController::class, 'listLot']);
    $router->post('/upload-ke-hoach-xuat-kho', [ApiMobileController::class, 'uploadKHXK']);
    $router->get('/production-plan/list', [ApiUIController::class, 'getListProductionPlan']);
    $router->get('/warehouse/list-export-plan', [ApiMobileController::class, 'getListWareHouseExportPlan']);
    $router->post('/upload-info-cong-doan', [Phase2UIApiController::class, 'uploadInfoCongDoan']);
    $router->post('/upload-warehouse-location', [Phase2UIApiController::class, 'uploadWarehouseLocation']);

    //// ROUTE CỦA AN
    $router->get('line/list-machine', [ApiMobileController::class, 'getMachineOfLine']);
    $router->get('line/machine/check-sheet', [ApiMobileController::class, 'getChecksheetOfMachine']);
    $router->get('line/error', [ApiMobileController::class, 'lineError']);
    $router->get('machine/logs', [ApiMobileController::class, 'logsMachine']);
    $router->get('machine/overall', [ApiMobileController::class, 'machineOverall']);
    ///HẾT

    //EXPORT
    $router->get('/export/produce/history', [ApiUIController::class, 'exportProduceHistory']);
    $router->get('/export/machine_error', [ApiUIController::class, 'exportMachineError']);
    $router->get('/export/thong-so-may', [ApiUIController::class, 'exportThongSoMay']);
    $router->get('/export/warehouse/history', [ApiUIController::class, 'exportHistoryWarehouse']);
    $router->get('/export/qc/history/pqc', [ApiUIController::class, 'exportQCHistoryPQC']);
    $router->get('/export/oqc', [ApiUIController::class, 'exportOQC']);
    $router->get('/export/pqc', [ApiUIController::class, 'exportPQC']);
    $router->get('/export/qc-history', [ApiUIController::class, 'exportQCHistory']);
    $router->get('/export/report-qc', [ApiUIController::class, 'exportReportQC']);
    $router->get('/export/qc-error-list', [ApiUIController::class, 'exportQCErrorList']);
    $router->get('/export/report-produce-history', [ApiUIController::class, 'exportReportProduceHistory']);
    $router->get('/export/warehouse/summary', [ApiUIController::class, 'exportSummaryWarehouse']);
    $router->get('/export/warehouse/bmcard', [ApiUIController::class, 'exportBMCardWarehouse']);
    $router->get('/export/warehouse/inventory', [ApiUIController::class, 'exportInventoryWarehouse']);
    $router->get('/export/kpi', [ApiUIController::class, 'exportKPI']);
    $router->get('/export/history-monitors', [ApiUIController::class, 'exportHistoryMonitors']);

    $router->get('ui/qc-error-list', [ApiUIController::class, 'qcErrorList']);
    $router->get('ui/data-filter', [ApiUIController::class, 'getDataFilterUI']);

    $router->get('info-cong-doan/list', [InfoCongDoanController::class, 'getInfoCongDoan']);
    $router->get('info-cong-doan/search', [InfoCongDoanController::class, 'searchInfoCongDoan']);
    $router->post('info-cong-doan/update', [InfoCongDoanController::class, 'updateInfoCongDoan']);
    $router->get('info-cong-doan/export', [InfoCongDoanController::class, 'exportInfoCongDoan']);
    $router->post('info-cong-doan/import', [InfoCongDoanController::class, 'importInfoCongDoan']);

    $router->get('machine/list', [App\Admin\Controllers\MachineController::class, 'getMachine']);
    $router->patch('machine/update', [App\Admin\Controllers\MachineController::class, 'updateMachine']);
    $router->post('machine/create', [App\Admin\Controllers\MachineController::class, 'createMachine']);
    $router->post('machine/delete', [App\Admin\Controllers\MachineController::class, 'deleteMachine']);
    $router->get('machine/export', [App\Admin\Controllers\MachineController::class, 'exportMachine']);
    $router->post('machine/import', [App\Admin\Controllers\MachineController::class, 'importMachine']);

    $router->get('spec-product/list', [App\Admin\Controllers\ProductController::class, 'getSpecProduct']);
    $router->patch('spec-product/update', [App\Admin\Controllers\ProductController::class, 'updateSpecProduct']);
    $router->post('spec-product/create', [App\Admin\Controllers\ProductController::class, 'createSpecProduct']);
    $router->post('spec-product/delete', [App\Admin\Controllers\ProductController::class, 'deleteSpecProduct']);
    $router->get('spec-product/export', [App\Admin\Controllers\ProductController::class, 'exportSpecProduct']);
    $router->post('spec-product/import', [App\Admin\Controllers\ProductController::class, 'importNewVersion']);

    $router->get('errors/list', [App\Admin\Controllers\ErrorController::class, 'getErrors']);
    $router->patch('errors/update', [App\Admin\Controllers\ErrorController::class, 'updateErrors']);
    $router->post('errors/create', [App\Admin\Controllers\ErrorController::class, 'createErrors']);
    $router->post('errors/delete', [App\Admin\Controllers\ErrorController::class, 'deleteErrors']);
    $router->get('errors/export', [App\Admin\Controllers\ErrorController::class, 'exportErrors']);
    $router->post('errors/import', [App\Admin\Controllers\ErrorController::class, 'importErrors']);

    $router->get('test_criteria/list', [App\Admin\Controllers\TestCriteriaController::class, 'getTestCriteria']);
    $router->patch('test_criteria/update', [App\Admin\Controllers\TestCriteriaController::class, 'updateTestCriteria']);
    $router->post('test_criteria/create', [App\Admin\Controllers\TestCriteriaController::class, 'createTestCriteria']);
    $router->post('test_criteria/delete', [App\Admin\Controllers\TestCriteriaController::class, 'deleteTestCriteria']);
    $router->get('test_criteria/export', [App\Admin\Controllers\TestCriteriaController::class, 'exportTestCriteria']);
    $router->post('test_criteria/import', [App\Admin\Controllers\TestCriteriaController::class, 'importTestCriteria']);

    $router->get('cong-doan/list', [App\Admin\Controllers\LineController::class, 'getLine']);
    $router->patch('cong-doan/update', [App\Admin\Controllers\LineController::class, 'updateLine']);
    $router->post('cong-doan/create', [App\Admin\Controllers\LineController::class, 'createLine']);
    $router->post('cong-doan/delete', [App\Admin\Controllers\LineController::class, 'deleteLine']);
    $router->get('cong-doan/export', [App\Admin\Controllers\LineController::class, 'exportLine']);
    $router->post('cong-doan/import', [App\Admin\Controllers\LineController::class, 'importLine']);

    $router->get('users/list', [App\Admin\Controllers\CustomAdminController::class, 'getUsers']);
    $router->get('users/roles', [App\Admin\Controllers\CustomAdminController::class, 'getUserRoles']);
    $router->patch('users/update', [App\Admin\Controllers\CustomAdminController::class, 'updateUsers']);
    $router->post('users/create', [App\Admin\Controllers\CustomAdminController::class, 'createUsers']);
    $router->post('users/delete', [App\Admin\Controllers\CustomAdminController::class, 'deleteUsers']);
    $router->get('users/export', [App\Admin\Controllers\CustomAdminController::class, 'exportUsers']);
    $router->post('users/import', [App\Admin\Controllers\CustomAdminController::class, 'importUsers']);

    $router->get('roles/list', [App\Admin\Controllers\RoleController::class, 'getRoles']);
    $router->get('roles/permissions', [App\Admin\Controllers\RoleController::class, 'getPermissions']);
    $router->patch('roles/update', [App\Admin\Controllers\RoleController::class, 'updateRole']);
    $router->post('roles/create', [App\Admin\Controllers\RoleController::class, 'createRole']);
    $router->post('roles/delete', [App\Admin\Controllers\RoleController::class, 'deleteRoles']);
    $router->get('roles/export', [App\Admin\Controllers\RoleController::class, 'exportRoles']);
    $router->post('roles/import', [App\Admin\Controllers\RoleController::class, 'importRoles']);

    $router->get('permissions/list', [App\Admin\Controllers\PermissionController::class, 'getPermissions']);
    $router->patch('permissions/update', [App\Admin\Controllers\PermissionController::class, 'updatePermission']);
    $router->post('permissions/create', [App\Admin\Controllers\PermissionController::class, 'createPermission']);
    $router->post('permissions/delete', [App\Admin\Controllers\PermissionController::class, 'deletePermissions']);
    $router->get('permissions/export', [App\Admin\Controllers\PermissionController::class, 'exportPermissions']);
    $router->post('permissions/import', [App\Admin\Controllers\PermissionController::class, 'importPermissions']);

    $router->get('product/list', [App\Admin\Controllers\ProductController::class, 'list']);
    $router->patch('product/update/{id}', [App\Admin\Controllers\ProductController::class, 'update']);
    $router->post('product/create', [App\Admin\Controllers\ProductController::class, 'create']);
    $router->delete('product/delete/{id}', [App\Admin\Controllers\ProductController::class, 'delete']);
    $router->post('products/delete', [App\Admin\Controllers\ProductController::class, 'deleteMultiple']);
    $router->get('product/export', [App\Admin\Controllers\ProductController::class, 'export']);
    $router->post('product/import', [App\Admin\Controllers\ProductController::class, 'importNewVersion']);

    $router->get('material/list', [App\Admin\Controllers\MaterialController::class, 'list']);
    $router->patch('material/update/{id}', [App\Admin\Controllers\MaterialController::class, 'update']);
    $router->post('material/create', [App\Admin\Controllers\MaterialController::class, 'create']);
    $router->delete('material/delete/{id}', [App\Admin\Controllers\MaterialController::class, 'delete']);
    $router->post('materials/delete', [App\Admin\Controllers\MaterialController::class, 'deleteMultiple']);
    $router->get('material/export', [App\Admin\Controllers\MaterialController::class, 'exportLine']);
    $router->post('material/import', [App\Admin\Controllers\MaterialController::class, 'importLine']);

    $router->get('customer/list', [App\Admin\Controllers\CustomerApiController::class, 'list']);
    $router->patch('customer/update/{id}', [App\Admin\Controllers\CustomerApiController::class, 'update']);
    $router->post('customer/create', [App\Admin\Controllers\CustomerApiController::class, 'create']);
    $router->delete('customer/delete/{id}', [App\Admin\Controllers\CustomerApiController::class, 'delete']);
    $router->post('customers/delete', [App\Admin\Controllers\CustomerApiController::class, 'deleteMultiple']);
    $router->get('customer/export', [App\Admin\Controllers\CustomerApiController::class, 'exportLine']);
    $router->post('customer/import', [App\Admin\Controllers\CustomerApiController::class, 'import']);

    $router->get('product-order/list', [App\Admin\Controllers\ProductOrderController::class, 'list']);
    $router->patch('product-order/update/{id}', [App\Admin\Controllers\ProductOrderController::class, 'update']);
    $router->post('product-order/create', [App\Admin\Controllers\ProductOrderController::class, 'create']);
    $router->delete('product-order/delete/{id}', [App\Admin\Controllers\ProductOrderController::class, 'delete']);
    $router->post('product-orders/delete', [App\Admin\Controllers\ProductOrderController::class, 'deleteMultiple']);
    $router->get('product-order/export', [App\Admin\Controllers\ProductOrderController::class, 'exportLine']);
    $router->post('product-order/import', [App\Admin\Controllers\ProductOrderController::class, 'import']);
    $router->post('product-order/update-number-machine', [App\Admin\Controllers\ProductOrderController::class,'updateNumberMachine']);

    $router->get('template/list', [App\Admin\Controllers\TemplateController::class, 'list']);
    $router->patch('template/update/{id}', [App\Admin\Controllers\TemplateController::class, 'update']);
    $router->post('template/create', [App\Admin\Controllers\TemplateController::class, 'create']);
    $router->delete('template/delete/{id}', [App\Admin\Controllers\TemplateController::class, 'delete']);
    $router->post('templates/delete', [App\Admin\Controllers\TemplateController::class, 'deleteMultiple']);
    $router->post('template/import', [App\Admin\Controllers\TemplateController::class, 'import']);

    $router->get('user-info/list', [App\Admin\Controllers\UserInfoController::class, 'list']);
    $router->patch('user-info/update/{id}', [App\Admin\Controllers\UserInfoController::class, 'update']);
    $router->post('user-info/create', [App\Admin\Controllers\UserInfoController::class, 'create']);
    $router->delete('user-info/delete/{id}', [App\Admin\Controllers\UserInfoController::class, 'delete']);
    $router->post('user-infos/delete', [App\Admin\Controllers\UserInfoController::class, 'deleteMultiple']);
    $router->get('user-info/export', [App\Admin\Controllers\UserInfoController::class, 'exportLine']);
    $router->post('user-info/import', [App\Admin\Controllers\UserInfoController::class, 'import']);

    $router->get('bom/list', [App\Admin\Controllers\BomController::class, 'list']);
    $router->patch('bom/update/{id}', [App\Admin\Controllers\BomController::class, 'update']);
    $router->post('bom/create', [App\Admin\Controllers\BomController::class, 'create']);
    $router->delete('bom/delete/{id}', [App\Admin\Controllers\BomController::class, 'delete']);
    $router->post('boms/delete', [App\Admin\Controllers\BomController::class, 'deleteMultiple']);
    $router->get('bom/export', [App\Admin\Controllers\BomController::class, 'exportLine']);
    $router->post('bom/import', [App\Admin\Controllers\BomController::class, 'importLine']);


    $router->get('update-du-lieu', [ApiMobileController::class, 'updateDuLieu']);

    $router->get('production_plan/export', [ApiUIController::class, 'exportKHSX']);
    $router->get('qc/history-checksheet/', [ApiMobileController::class, 'getHistoryChecksheet']);
    $router->get('qc/error-data/', [ApiUIController::class, 'errorData']);

    $router->get('ui/iqc', [ApiUIController::class, 'iqcCheckedList']);
    $router->get('ui/iqc/export', [ApiUIController::class, 'exportIQCHistory']);
    $router->get('ui/equipment/power-consume-by-month', [ApiUIController::class, 'powerConsumeByMonth']);
    $router->get('ui/equipment/power-consume-by-month-chart', [ApiUIController::class, 'powerConsumeByMonthChart']);
    $router->get('ui/equipment/power-consume-by-product', [ApiUIController::class, 'powerConsumeByProduct']);
    $router->get('ui/export/power-consume-by-product', [ApiUIController::class, 'exportPowerConsumeByProduct']);

    $router->get('abnormal/detail', [ApiUIController::class, 'detailAbnormal']);

    $router->get('update/product/info_cong_doan', [ApiUIController::class, 'updateProductIdInfoCongDoan']);
    $router->get('update/kho_bao_on', [ApiUIController::class, 'updateSanLuongKhoBaoOn']);
    $router->get('update-material-name', [ApiUIController::class, 'updateMaterialName']);

    $router->get('kpi/productivity', [KPIController::class, 'KPIProductivity']);
    $router->get('kpi/pass-rate', [KPIController::class, 'KPIPassRate']);

    $router->post('error-machine/import', [ErrorMachineApiController::class, 'import']);
    $router->post('check-sheet/import', [CheckSheetApiController::class, 'import']);
});

//Route Phase 2
//No Auth
Route::group([
    'prefix'        => "/api",
    'middleware'    => [],
], function (Router $router) {
    $router->get('update-material-name', [ApiUIController::class, 'updateMaterialName']);
    $router->get('create-pptx', [ExportFileController::class, 'createPPTX']);
    $router->post('import-btbd', [MaintenanceScheduleController::class, 'import']);
    $router->post('maintenance-log-images/upload', [MaintenanceLogImageController::class, 'upload']);

    $router->post('create-lot-demo', [Phase2OIApiController::class, 'createLotDemo']);

    $router->post('/iot/update-quantity', [IOTController::class, 'updateQuantityFromIot']);
    $router->post('/iot/update-params', [IOTController::class, 'updateParamsFromIot']);
    $router->post('/iot/update-status', [IOTController::class, 'updateStatusFromIot']);
    $router->post('/iot/record-product-output', [IOTController::class, 'recordProductOutput']);

    Route::post('/import-parameters', [ParameterController::class, 'import']);
    $router->post('/import-spec', [App\Admin\Controllers\ProductController::class, 'importNewVersion']);
    $router->get('/update-info-cong-doan', [App\Admin\Controllers\ApiUIController::class,'test']);
    $router->get('convertQCLog', [App\Admin\Controllers\ApiUIController::class,'convertQCLog']);
    $router->get('get-tem', [App\Admin\Controllers\StampController::class,'createTem']);
});
//Dashboard
Route::group([
    'prefix'        => "/api/p2/dasboard",
    'middleware'    => [],
], function (Router $router) {
    $router->post('update-production', [Phase2OIApiController::class, 'updateProduction']);
    $router->get('/produce/fmb', [Phase2DBApiController::class, 'fmb']);
    $router->get('/machine-performance', [Phase2DBApiController::class, 'getMachinePerformance']);
    $router->post('/test-api', [Phase2DBApiController::class, 'handle']);

    $router->get('/production-situation-line-in', [Phase2DBApiController::class, 'getProductionSituationLineIn']);
    $router->get('/production-situation-by-machine', [Phase2DBApiController::class, 'getProductionSituationByMachine']);
});
//OI
Route::group([
    'prefix'        => "/api/p2/oi",
    'middleware'    => "auth:sanctum",
], function (Router $router) {
    //Sản xuất
    $router->get('line-list', [Phase2OIApiController::class, 'getLineList']);
    $router->get('machine-list', [Phase2OIApiController::class, 'getMachineList']);
    $router->get('production-overall', [Phase2OIApiController::class, 'getProductionOverall']);
    $router->get('lot-production-list', [Phase2OIApiController::class, 'getLotProductionList']);
    $router->post('scan-material', [Phase2OIApiController::class, 'scanMaterial']);
    $router->post('scan-manufacture', [Phase2OIApiController::class, 'scanManufacture']);
    $router->get('lot-error-log-list', [Phase2OIApiController::class, 'getLotErrorLogList']);
    $router->post('find-error', [Phase2OIApiController::class, 'findError']);
    $router->post('update-lot-error-log', [Phase2OIApiController::class, 'updateLotErrorLog']);
    $router->post('end-of-production', [Phase2OIApiController::class, 'endOfProduction']);
    $router->post('scan-for-selection-line', [Phase2OIApiController::class, 'scanForSelectionLine']);
    $router->get('assignment', [Phase2OIApiController::class, 'getAssignment']);
    $router->post('assignment', [Phase2OIApiController::class, 'createAssignment']);
    $router->delete('assignment/{id}', [Phase2OIApiController::class, 'deleteAssignment']);
    $router->patch('assignment/{id}', [Phase2OIApiController::class, 'updateAssignment']);
    $router->post('print-tem-selection-line', [Phase2OIApiController::class, 'printTemSelectionLine']);
    $router->post('update-output-production', [Phase2OIApiController::class,'updateOutputProduction']);

    //Chất lượng
    $router->get('qc-overall', [Phase2OIApiController::class, 'getQCOverall']);
    $router->get('lot-qc-list', [Phase2OIApiController::class, 'getLotQCList']);
    $router->post('scan-qc', [Phase2OIApiController::class, 'scanQC']);
    $router->get('criteria-list-of-lot', [Phase2OIApiController::class, 'getCriteriaListOfLot']);
    $router->post('save-pqc-result', [Phase2OIApiController::class, 'savePQCResult']);
    $router->post('update-error-log', [Phase2OIApiController::class, 'updateErrorLog']);
    $router->post('update-tem-vang-quantity', [Phase2OIApiController::class, 'updateTemVangQuantity']);
    $router->post('check-eligible-for-printing', [Phase2OIApiController::class, 'checkEligibleForPrinting']);
    $router->post('print-tem-vang', [Phase2OIApiController::class, 'printTemVang']);
    $router->post('scan-oqc', [Phase2OIApiController::class, 'scanOQC']);

    //Thiết bị
    $router->get('machine-overall', [Phase2OIApiController::class, 'getMachineOverall']);

    //Kho
    $router->get('scan-import', [Phase2OIApiController::class, 'scanImport']);
});
//UI
Route::group([
    'prefix'        => "/api/p2/ui",
    'middleware'    => "auth:sanctum",
], function (Router $router) {
    $router->get('/tree-select', [Phase2UIApiController::class, 'getTreeSelect']);
    $router->get('/production-history', [Phase2UIApiController::class, 'getProductionHistory']);

    Route::apiResource('maintenance-categories', MaintenanceCategoryController::class);
    Route::apiResource('maintenance-items', MaintenanceItemController::class);
    Route::apiResource('machines', MachineController::class);
    Route::apiResource('maintenance-plans', MaintenancePlanController::class);
    Route::apiResource('maintenance-schedules', MaintenanceScheduleController::class);
    Route::apiResource('maintenance-logs', MaintenanceLogController::class);
    Route::apiResource('maintenance-log-images', MaintenanceLogImageController::class);

    $router->get('maintenance-plans/list/plan', [MaintenancePlanController::class, 'list']);
    $router->get('maintenance-plans/detail/list', [MaintenancePlanController::class, 'detail']);
    $router->post('maintenance-plans/import', [MaintenanceScheduleController::class, 'import']);

    $router->get('equipment/oee', [Phase2UIApiController::class, 'getOEEData']);
    $router->get('equipment/error-frequency', [Phase2UIApiController::class, 'getErrorFrequencyData']);
    
    $router->get('quality/pqc/data-table', [Phase2UIApiController::class, 'getQualityDataTable']);
    $router->get('quality/pqc/data-chart', [Phase2UIApiController::class, 'getQualityDataChart']);
    $router->post('plan/generate', [Phase2UIApiController::class, 'generateProductionPlan']);
    $router->get('plan/store/{order_id}', [Phase2UIApiController::class, 'processProductionPlan']);
    $router->post('plan/create', [Phase2UIApiController::class, 'createProductionPlan']);
});

//UI
Route::group([
    'prefix'        => "/api/p2/ui/master-data",
    'middleware'    => "auth:sanctum",
], function (Router $router) {
    Route::apiResource('stamps', StampController::class);
    Route::post('stamps/import', [StampController::class, 'import']);
});
