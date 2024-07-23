const WebSocket = require('ws');
const axios = require('axios');
// Cấu hình API và WebSocket
const base_url = 'https:/backtl.ors.vn/api/iot';
const LOGIN_API_URL = 'http://103.77.215.18:3030/api/auth/login';
const WEBSOCKET_URL = 'ws://103.77.215.18:3030/api/ws/plugins/telemetry';
const PRODUCTION_API_URL = base_url + '/update-quantity';
const MACHINE_INFO_API_URL = base_url + '/update-params';
const MACHINE_STATUS_API_URL = base_url + '/update-status';
const MACHINE_RECORD_API_URL = base_url + '/record-product-output';
const DEVICE_IDS = ['f7f77560-45bd-11ef-b8c3-a13625245eca']; // Thay thế bằng danh sách mã thiết bị thực tế

// Thông tin đăng nhập
const credentials = {
    username: 'messystem@gmail.com',
    password: 'mesors@2023'
};

// Biến lưu trữ token
let authToken = '';

// Hàng đợi dữ liệu và biến theo dõi trạng thái cho từng thiết bị
let dataQueues = {};
let isProcessing = {};

// Biến lưu trữ giá trị cuối cùng cho sản lượng và trạng thái máy
let lastProductionValues = {};
let lastMachineStatusValues = {};

// Hàm lấy token
async function getAuthToken() {
    try {
        const response = await axios.post(LOGIN_API_URL, credentials, {
            headers: {
                'Content-Type': 'application/json'
            }
        });
        authToken = response.data.token;
        return authToken;
    } catch (error) {
        console.error('Error fetching auth token:', error.message);
        throw new Error('Failed to get auth token');
    }
}

// Hàm gửi dữ liệu tới MES API tương ứng
async function pushDataToMESAPI(data, apiUrl) {
    try {
        const response = await axios.post(apiUrl, data, {
            headers: {
                'Authorization': `Bearer ${authToken}`,
                'Content-Type': 'application/json'
            }
        });
        // console.log(`Data pushed to API ${apiUrl}:`, response.data);
    } catch (error) {
        if (error.response && error.response.status === 401) {
            // Token hết hạn, lấy lại token mới và thử lại
            console.log('Token expired, fetching new token...');
            await getAuthToken();
            await pushDataToMESAPI(data, apiUrl); // Thử lại với token mới
        } else {
            console.error(`Error pushing data to API ${apiUrl}:`, error.message);
        }
    }
}

// Hàm xử lý hàng đợi
async function processQueue(deviceId) {
    if (isProcessing[deviceId] || dataQueues[deviceId].length === 0) return;
    isProcessing[deviceId] = true;

    const { data, apiUrl } = dataQueues[deviceId].shift();
    await pushDataToMESAPI(data, apiUrl);

    isProcessing[deviceId] = false;
    if (dataQueues[deviceId].length > 0) {
        processQueue(deviceId);
    }
}

// Hàm chuyển đổi dữ liệu sản lượng
function convertProductionData(data, deviceId) {
    return {
        device_id: deviceId,
        input: data['PLC:Num_Input'][0][0],
        output: data['PLC:Num_Out'][0][0],
    };
}

// Hàm chuyển đổi dữ liệu thông số máy
function convertMachineInfoData(data, deviceId) {
    return {
        device_id: deviceId,
        tem_lieu: data['HMI_Mixing:Tem_Lieu'][0][1],
        tem_vo: data['HMI_Mixing:Tem_Vo'][0][1],
    };
}

// Hàm chuyển đổi dữ liệu trạng thái máy
function convertMachineStatusData(data, deviceId) {
    return {
        device_id: deviceId,
        status: data['PLC:STATUS'][0][1],
    };
}

function convertMachineRecordData(data, deviceId) {
    return {
        device_id: deviceId,
    };
}

// Hàm phân loại dữ liệu và đẩy vào hàng đợi
function enqueueData(deviceId, data) {
    // Chuyển đổi và đẩy dữ liệu sản lượng
    if (data['PLC:Num_Input'] && data['PLC:Num_Out']) {
        let convertedData = convertProductionData(data, deviceId);
        if (JSON.stringify(lastProductionValues[deviceId]) !== JSON.stringify(data)) {
            dataQueues[deviceId].push({ data: convertedData, apiUrl: PRODUCTION_API_URL });
            lastProductionValues[deviceId] = data;
        }
    }

    // Chuyển đổi và đẩy dữ liệu thông số máy
    if (data['HMI_Mixing:Tem_Vo'] && data['HMI_Mixing:Tem_Lieu']) {
        let convertedData = convertMachineInfoData(data, deviceId);
        dataQueues[deviceId].push({ data: convertedData, apiUrl: MACHINE_INFO_API_URL });
    }

    // Chuyển đổi và đẩy dữ liệu trạng thái máy
    if (data['PLC:STATUS']) {
        let convertedData = convertMachineStatusData(data, deviceId);
        if (JSON.stringify(lastMachineStatusValues[deviceId]) !== JSON.stringify(data)) {
            console.log(convertedData);
            dataQueues[deviceId].push({ data: convertedData, apiUrl: MACHINE_STATUS_API_URL });
            lastMachineStatusValues[deviceId] = data;
        }
    }

    if (data['PLC:Count_En']) {
        let convertedData = convertMachineRecordData(data, deviceId);
        if (JSON.stringify(lastMachineStatusValues[deviceId]) !== JSON.stringify(data)) {
            if (data['PLC:Count_En'][0][1] == 1) {
                dataQueues[deviceId].push({ data: convertedData, apiUrl: MACHINE_RECORD_API_URL });
            }
            lastMachineStatusValues[deviceId] = data;
        }
    }

    processQueue(deviceId);
}

// Hàm kết nối WebSocket cho từng thiết bị
async function connectWebSocket(deviceId) {
    await getAuthToken();
    const ws = new WebSocket(`${WEBSOCKET_URL}?token=${authToken}`);
    ws.onopen = function () {
        var object = {
            tsSubCmds: [
                {
                    "entityType": "DEVICE",
                    "entityId": deviceId,
                    "scope": "LATEST_TELEMETRY",
                    "cmdId": 1
                }
            ],
            historyCmds: [],
            attrSubCmds: []
        };
        var data = JSON.stringify(object);
        ws.send(data);
    }

    ws.on('open', () => {
        console.log(`Connected to WebSocket for device ${deviceId}`);
    });

    ws.on('message', async (data) => {
        try {
            const parsedData = JSON.parse(data);
            // console.log(parsedData.data);
            // console.log(`Received data from ${deviceId}:`, parsedData.data);

            // Thêm mã thiết bị vào dữ liệu
            parsedData.data.device_id = deviceId;
            // const data = parsedData.data;
            enqueueData(deviceId, parsedData.data);
        } catch (error) {
            console.error(`Error processing data from ${deviceId}:`, error.message);
        }
    });

    ws.on('close', () => {
        console.log(`WebSocket connection closed for device ${deviceId}`);
        setTimeout(() => reconnectWebSocket(deviceId), 5000);
    });

    ws.on('error', (error) => {
        console.error(`WebSocket error for device ${deviceId}:`, error.message);
    });
}
function reconnectWebSocket(deviceId) {
    console.log(`Reconnecting WebSocket for device ${deviceId}...`);
    connectWebSocket(deviceId).catch(error => {
        console.error(`Failed to reconnect to WebSocket for device ${deviceId}:`, error.message);
        setTimeout(() => reconnectWebSocket(deviceId), 5000); // Thử kết nối lại sau 5 giây
    });
}

// Kết nối tới WebSocket cho từng thiết bị trong danh sách
async function connectAllDevices() {
    await getAuthToken();

    for (const deviceId of DEVICE_IDS) {
        dataQueues[deviceId] = [];
        isProcessing[deviceId] = false;
        lastProductionValues[deviceId] = null;
        lastMachineStatusValues[deviceId] = null;
        connectWebSocket(deviceId).catch(error => {
            console.error(`Failed to connect to WebSocket for device ${deviceId}:`, error.message);
            setTimeout(() => reconnectWebSocket(deviceId), 5000);
        });
    }
}

connectAllDevices().catch(error => {
    console.error('Failed to connect to WebSocket for all devices:', error.message);
});
