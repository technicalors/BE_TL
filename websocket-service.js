const WebSocket = require("ws");
const axios = require("axios");
// Cấu hình API và WebSocket
const base_url = "https://backtl.ors.vn/api/iot";
const LOGIN_API_URL = "http://103.77.215.18:3030/api/auth/login";
const WEBSOCKET_URL = "ws://103.77.215.18:3030/api/ws/plugins/telemetry";
const PRODUCTION_API_URL = base_url + "/update-quantity";
const MACHINE_INFO_API_URL = base_url + "/update-params";
const MACHINE_STATUS_API_URL = base_url + "/update-status";
const MACHINE_RECORD_API_URL = base_url + "/record-product-output";
const POWER_CONSUME_API_URL = base_url + "/power-consume";
const DEVICE_IDS = [
  "f7f77560-45bd-11ef-b8c3-a13625245eca",
  "7cda31d0-45bb-11ef-b8c3-a13625245eca",
  "da03f550-45be-11ef-b8c3-a13625245eca",
  "a43d8520-45bf-11ef-b8c3-a13625245eca",
  "22d821e0-45bd-11ef-b8c3-a13625245eca",
  "af35a2e0-45c0-11ef-b8c3-a13625245eca",
  "9032a0e0-45bc-11ef-b8c3-a13625245eca",
  "40a1abc0-45bc-11ef-b8c3-a13625245eca",
  "886de160-45be-11ef-b8c3-a13625245eca",
  "2a9b5df0-45bf-11ef-b8c3-a13625245eca",
  "7b85a180-45bf-11ef-b8c3-a13625245eca",
]; 

// Thông tin đăng nhập
const credentials = {
  username: "messystem@gmail.com",
  password: "mesors@2023",
};

// Biến lưu trữ token
let authToken = "";

// Hàng đợi dữ liệu và biến theo dõi trạng thái cho từng thiết bị
let dataQueues = {};
let isProcessing = {};

// Biến lưu trữ giá trị cuối cùng cho sản lượng và trạng thái máy
let lastProductionValues = {};
let lastMachineStatusValues = {};
let lastMachineRecordValues = {};

const deviceFieldConfig = {
  "22d821e0-45bd-11ef-b8c3-a13625245eca": {
    PLC_CB01: "PLC:CB01",
    PLC_CB02: "PLC:CB02",
    PLC_CB03: "PLC:CB03",
    PLC_CB04: "PLC:CB04",
    PLC_CB05: "PLC:CB05",
    PLC_CB06: "PLC:CB06",
    PLC_CB07: "PLC:CB07",
    PLC_CB08: "PLC:CB08",
    PLC_CB09: "PLC:CB09",
    PLC_CB10: "PLC:CB10",
    PLC_CB11: "PLC:CB11",
    PLC_CB12: "PLC:CB12",
    PLC_CB13: "PLC:CB13",
    PLC_CB14: "PLC:CB14",
    PLC_AP01: "PLC:AP01",
    PLC_AP02: "PLC:AP02",
    PLC_AP03: "PLC:AP03",
    PM01_TEnergy: "PM01:Active_Energy",
    Env01_Temper: "ENV01:TEMPER",
    Env01_Humi: "ENV01:HUMI",
  },
  "40a1abc0-45bc-11ef-b8c3-a13625245eca": {
    PLC_UV1: "PLC:UV1",
    PLC_UV2: "PLC:UV2",
    PLC_STATUS: "PLC:STATUS",
    PLC_Time_UV1: "PLC:Time_UV1",
    PLC_Time_UV2: "PLC:Time_UV2",
    PM01_TEnergy: "PM01:Active_Energy",
  },
  "7cda31d0-45bb-11ef-b8c3-a13625245eca": {
    PLC_UV1: "PLC:UV1",
    PLC_UV2: "PLC:UV2",
    PLC_UV3: "PLC:UV3",
    PLC_UV4: "PLC:UV4",
    PLC_UV5: "PLC:UV5",
    PLC_UV6: "PLC:UV6",
    PLC_UV7: "PLC:UV7",
    PLC_UV8: "PLC:UV8",
    PLC_Time_UV1: "PLC:Time_UV1",
    PLC_Time_UV2: "PLC:Time_UV2",
    PLC_Time_UV3: "PLC:Time_UV3",
    PLC_Time_UV4: "PLC:Time_UV4",
    PLC_Time_UV5: "PLC:Time_UV5",
    PLC_Time_UV6: "PLC:Time_UV6",
    PLC_Time_UV7: "PLC:Time_UV7",
    PLC_Time_UV8: "PLC:Time_UV8",
    PM01_TEnergy: "PM01:Active_Energy",
    Env01_Temper: "ENV01:TEMPER",
    Env01_Humi: "ENV01:HUMI",
  },
  "886de160-45be-11ef-b8c3-a13625245eca": {
    PLC_CB01: "PLC:CB01",
    PLC_CB02: "PLC:CB02",
    PLC_CB03: "PLC:CB03",
    PLC_CB04: "PLC:CB04",
    PLC_CB05: "PLC:CB05",
    PLC_CB06: "PLC:CB06",
    PLC_CB07: "PLC:CB07",
    PLC_CB08: "PLC:CB08",
    PLC_CB09: "PLC:CB09",
    PLC_CB10: "PLC:CB10",
    PLC_CB11: "PLC:CB11",
    PLC_CB12: "PLC:CB12",
    PLC_CB13: "PLC:CB13",
    PLC_CB14: "PLC:CB14",
    PLC_AP01: "PLC:AP01",
    PLC_AP02: "PLC:AP02",
    PLC_AP03: "PLC:AP03",
    PM01_TEnergy: "PM01:Active_Energy",
  },
  "9032a0e0-45bc-11ef-b8c3-a13625245eca": {
    PLC_UV1: "PLC:UV1",
    PLC_UV2: "PLC:UV2",
    PLC_UV3: "PLC:UV3",
    PLC_UV4: "PLC:UV4",
    PLC_Time_UV1: "PLC:Time_UV1",
    PLC_Time_UV2: "PLC:Time_UV2",
    PLC_Time_UV3: "PLC:Time_UV3",
    PLC_Time_UV4: "PLC:Time_UV4",
    PM01_TEnergy: "PM01:Active_Energy",
  },
  "a43d8520-45bf-11ef-b8c3-a13625245eca": {
    PLC_F_Thu: "PLC:F_THU",
    PLC_F_xa: "PLC:F_Xa",
    PM01_TEnergy: "PM01:Active_Energy",
  },
  "af35a2e0-45c0-11ef-b8c3-a13625245eca": {
    PLC_F_Thu: "PLC:F_THU",
    PLC_F_xa: "PLC:F_Xa",
    PM01_TEnergy: "PM01:Active_Energy",
  },
  "da03f550-45be-11ef-b8c3-a13625245eca": {
    PM01_TEnergy: "PM01:Active_Energy",
  },
  "f7f77560-45bd-11ef-b8c3-a13625245eca": {
    PLC_CB01: "PLC:CB01",
    PLC_CB02: "PLC:CB02",
    PLC_CB03: "PLC:CB03",
    PLC_CB04: "PLC:CB04",
    PLC_CB05: "PLC:CB05",
    PLC_CB06: "PLC:CB06",
    PLC_CB07: "PLC:CB07",
    PLC_CB08: "PLC:CB08",
    PLC_CB09: "PLC:CB09",
    PLC_CB10: "PLC:CB10",
    PLC_CB11: "PLC:CB11",
    PLC_CB12: "PLC:CB12",
    PLC_CB13: "PLC:CB13",
    PLC_CB14: "PLC:CB14",
    PLC_AP01: "PLC:AP01",
    PLC_AP02: "PLC:AP02",
    PLC_AP03: "PLC:AP03",
    PM01_TEnergy: "PM01:Active_Energy",
  },
};

// Hàm lấy token
async function getAuthToken() {
  try {
    const response = await axios.post(LOGIN_API_URL, credentials, {
      headers: {
        "Content-Type": "application/json",
      },
    });
    authToken = response.data.token;
    return authToken;
  } catch (error) {
    console.error("Error fetching auth token:", error.message);
    throw new Error("Failed to get auth token");
  }
}

// Hàm gửi dữ liệu tới MES API tương ứng
async function pushDataToMESAPI(data, apiUrl) {
  try {
    const response = await axios.post(apiUrl, data, {
      headers: {
        Authorization: `Bearer ${authToken}`,
        "Content-Type": "application/json",
      },
    });
  } catch (error) {
    if (error.response && error.response.status === 401) {
      // Token hết hạn, lấy lại token mới và thử lại
      console.log("Token expired, fetching new token...");
      await getAuthToken();
      await pushDataToMESAPI(data, apiUrl); // Thử lại với token mới
    } else {
      console.error(
        `Error pushing data to API ${apiUrl}:`,
        error?.response?.data?.message ?? ""
      );
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
    input: data["PLC:Num_Input"] ? data["PLC:Num_Input"][0][1] : 0,
    output: data["PLC:Num_Out"] ? data["PLC:Num_Out"][0][1] : 0,
  };
}

// Hàm chuyển đổi dữ liệu thông số máy
function convertMachineInfoData(data, deviceId) {
  const specificFields = deviceFieldConfig[deviceId] || {};

  const convertedData = {
    device_id: deviceId,
  };

  // Chuyển đổi các trường riêng
  for (const [field, path] of Object.entries(specificFields)) {
    if (data[path]) {
      convertedData[field] = data[path][0][1];
    }
  }
  return convertedData;
}

function convertToNumber(value) {
  const number = parseFloat(value);
  return Math.round(number);
}
// Hàm chuyển đổi dữ liệu trạng thái máy
function convertMachineStatusData(data, deviceId) {
  return {
    device_id: deviceId,
    status: convertToNumber(data["PLC:STATUS"][0][1]),
  };
}

function convertMachineRecordData(data, deviceId) {
  return {
    device_id: deviceId,
  };
}

// Hàm phân loại dữ liệu và đẩy vào hàng đợi
async function enqueueData(deviceId, data) {
  // console.log('data',data);
  // Chuyển đổi và đẩy dữ liệu sản lượngPLC:Num_Out
  if (data["PLC:Num_Input"] || data["PLC:Num_Out"]) {
    let convertedData = convertProductionData(data, deviceId);
    if (
      JSON.stringify(lastProductionValues[deviceId]) !== JSON.stringify(data)
    ) {
      dataQueues[deviceId].push({
        data: convertedData,
        apiUrl: PRODUCTION_API_URL,
      });
      lastProductionValues[deviceId] = data;
    }
  }

  // Chuyển đổi và đẩy dữ liệu thông số máy
  if (deviceFieldConfig[deviceId]) {
    let convertedData = convertMachineInfoData(data, deviceId);
    if (Object.keys(convertedData).length > 1) {
      dataQueues[deviceId].push({
        data: convertedData,
        apiUrl: MACHINE_INFO_API_URL,
      });
    }
  }

  // Chuyển đổi và đẩy dữ liệu trạng thái máy
  if (data["PLC:STATUS"]) {
    let convertedData = convertMachineStatusData(data, deviceId);
    if (
      JSON.stringify(lastMachineStatusValues[deviceId]) !== JSON.stringify(data)
    ) {
      dataQueues[deviceId].push({
        data: convertedData,
        apiUrl: MACHINE_STATUS_API_URL,
      });
      lastMachineStatusValues[deviceId] = data;
    }
  }

  if (data["PLC:Count_En"]) {
    let convertedData = convertMachineRecordData(data, deviceId);
    if (data["PLC:Count_En"][0][1] == 1) {
      try {
        // Gọi API để lấy dữ liệu đầu vào và đầu ra
        // Đẩy dữ liệu vào hàng đợi
        dataQueues[deviceId].push({
          data: convertedData,
          apiUrl: MACHINE_RECORD_API_URL,
        });
      } catch (error) {
        console.error(
          `Error fetching data for device ${deviceId}:`,
          error.message
        );

        // Kiểm tra lỗi xác thực và xử lý lấy lại token nếu cần
        if (error.response && error.response.status === 401) {
          console.log("Token expired, fetching new token...");
          await getAuthToken(); // Lấy token mới
          // Thử lại quá trình gọi API với token mới
          await enqueueData(deviceId, data); // Thử lại xử lý hàng đợi với dữ liệu hiện tại
        }
      }
    }
  }

  // Tracking power consumed
  if (data["PM01:Active_Energy"]) {
    dataQueues[deviceId].push({
      data: {
        device_id: deviceId,
        value: data["PM01:Active_Energy"][0][1] ?? null,
      },
      apiUrl: POWER_CONSUME_API_URL,
    });
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
          entityType: "DEVICE",
          entityId: deviceId,
          scope: "LATEST_TELEMETRY",
          cmdId: 1,
        },
      ],
      historyCmds: [],
      attrSubCmds: [],
    };
    var data = JSON.stringify(object);
    ws.send(data);
  };

  ws.on("open", () => {
    console.log(`Connected to WebSocket for device ${deviceId}`);
    const heartbeatInterval = setInterval(() => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.ping(); // Gửi ping để giữ kết nối
      }
    }, 30000); // Ping mỗi 30 giây
  });

  ws.on("pong", () => {
    console.log(`Received pong from device ${deviceId}`);
  });

  ws.on("message", async (data) => {
    try {
      const parsedData = JSON.parse(data);

      // Thêm mã thiết bị vào dữ liệu
      parsedData.data.device_id = deviceId;
      // const data = parsedData.data;
      enqueueData(deviceId, parsedData.data);
    } catch (error) {
      console.error(`Error processing data from ${deviceId}:`, error.message);
    }
  });

  ws.on("close", () => {
    console.log(`WebSocket connection closed for device ${deviceId}`);
    clearInterval(heartbeatInterval); // Hủy timer ping
    setTimeout(() => reconnectWebSocket(deviceId), 5000);
  });

  ws.on("error", (error) => {
    console.error(`WebSocket error for device ${deviceId}:`, error.message);
  });
}
function reconnectWebSocket(deviceId) {
  console.log(`Reconnecting WebSocket for device ${deviceId}...`);
  setTimeout(async () => {
    try {
      await connectWebSocket(deviceId);
    } catch (error) {
      console.error(
        `Failed to reconnect to WebSocket for device ${deviceId}:`,
        error.message
      );
      reconnectWebSocket(deviceId); // Tiếp tục thử kết nối lại sau lỗi
    }
  }, 5000); // Thử kết nối lại sau 5 giây
}

// Kết nối tới WebSocket cho từng thiết bị trong danh sách
async function connectAllDevices() {
  await getAuthToken();
  for (const deviceId of DEVICE_IDS) {
    dataQueues[deviceId] = [];
    isProcessing[deviceId] = false;
    lastProductionValues[deviceId] = null;
    lastMachineStatusValues[deviceId] = null;
    lastMachineRecordValues[deviceId] = null;
    connectWebSocket(deviceId).catch((error) => {
      console.error(
        `Failed to connect to WebSocket for device ${deviceId}:`,
        error.message
      );
      setTimeout(() => reconnectWebSocket(deviceId), 5000);
    });
  }
}

connectAllDevices().catch((error) => {
  console.error(
    "Failed to connect to WebSocket for all devices:",
    error.message
  );
});
