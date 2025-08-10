<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE); 

// === 配置区域 ===
define('API_ENDPOINT', 'https://api.uapis.cn/api/v1/game/steam/summary');
define('API_KEY', '输入你的steamapi不会就查百度'); // 用户需要替换的API密钥
define('VERSION', '5.0.0');
define('LOG_FILE', 'steam_api_detailed_log.txt');

class Logger {
    public static function log($message, $data = null) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}\n";
        
        if ($data !== null) {
            $logEntry .= "数据: " . print_r($data, true) . "\n";
        }
        
        $logEntry .= "-------------------------\n";
        file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    }
}

// === 核心功能 ===
class SteamQuery {
    public static function validateSteamID($steamid) {
        $originalInput = $steamid;
        
        $cleanId = preg_replace('/[^\d]/u', '', $steamid);
      
        $originalLength = strlen($originalInput);
        $cleanLength = strlen($cleanId);
        
      
        Logger::log(
            "SteamID验证", 
            [
                'original_input' => $originalInput,
                'original_length' => $originalLength,
                'cleaned_input' => $cleanId,
                'cleaned_length' => $cleanLength,
                'is_valid' => $cleanLength === 17
            ]
        );
        
        if ($cleanLength === 17) {
            return $cleanId;
        } else {
            return [
                'error' => "SteamID格式错误",
                'details' => "清洗后长度为{$cleanLength}位，需要17位纯数字",
                'cleaned' => $cleanId
            ];
        }
    }
    
    public static function createContext($proxy = []) {
        $options = [
            'http' => [
                'timeout' => 30,
                'header' => [
                    'User-Agent: SteamQueryTool/' . VERSION,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ]
            ]
        ];
        
        if (!empty($proxy['enable'])) {
            $proxyStr = $proxy['server'] . ':' . $proxy['port'];
            $options['http']['proxy'] = "tcp://{$proxyStr}";
            $options['http']['request_fulluri'] = true;
            
            if (strtoupper($proxy['type']) === 'SOCKS5') {
                $options['http']['protocol_version'] = 1.1;
                $options['http']['header'][] = 'Proxy-Connection: Keep-Alive';
            }
        }
        
        return stream_context_create($options);
    }
    
    /**
     * 发送API请求
     */
    public static function fetchUserData($steamid, $proxy = []) {
        try {
            // 验证SteamID
            $validationResult = self::validateSteamID($steamid);
            
            // 检查验证结果
            if (is_array($validationResult)) {
                // 验证失败，返回详细错误
                Logger::log("输入验证失败: {$validationResult['error']}", $validationResult);
                return [
                    'error' => $validationResult['error'],
                    'debug' => $validationResult['details'],
                    'cleaned_input' => $validationResult['cleaned']
                ];
            }
            
            $validId = $validationResult;
            
            $params = http_build_query([
                'steamid' => $validId,
                'key' => API_KEY
            ]);
            
            $url = API_ENDPOINT . '?' . $params;
            Logger::log("发送API请求", ['url' => $url, 'proxy' => $proxy]);
            
            
            $context = self::createContext($proxy);
            $response = file_get_contents($url, false, $context);
            
            
            Logger::log("API原始响应", ['length' => strlen($response ?? ''), 'response' => $response]);
            
            // 检查响应是否为空
            if ($response === false) {
                $error = 'API请求失败，无法获取响应';
                Logger::log("请求失败: {$error}");
                return ['error' => $error];
            }
            
            if (strpos($response, '<!DOCTYPE html') !== false || strpos($response, '<html') !== false) {
                $error = 'API返回HTML内容，可能是访问被拦截或API服务异常';
                Logger::log("响应格式错误: {$error}");
                return ['error' => $error, 'debug' => '检测到HTML响应，请检查API密钥和网络设置'];
            }
            
            
            $data = json_decode($response, true);
            $jsonError = json_last_error();
            
            if ($jsonError !== JSON_ERROR_NONE) {
                $error = "JSON解析失败: " . json_last_error_msg();
                Logger::log("解析错误: {$error}", ['response' => $response]);
                return ['error' => $error, 'debug' => 'API返回无效JSON格式，请检查API服务状态'];
            }
            
            
            if (isset($data['code']) && $data['code'] !== 200) {
                $error = $data['message'] ?? '查询失败: 未知错误';
                Logger::log("API错误响应", ['code' => $data['code'], 'message' => $error]);
                return ['error' => $error];
            }
            
            return self::formatUserData($data);
            
        } catch (Exception $e) {
            $error = '请求异常: ' . $e->getMessage();
            Logger::log("请求异常: {$error}", ['exception' => $e]);
            return ['error' => $error];
        }
    }
    
    
    private static function formatUserData($apiData) {
        $statusMap = [
            0 => '离线', 1 => '在线', 2 => '忙碌', 3 => '离开',
            4 => '休息', 5 => '寻找交易', 6 => '寻找游戏'
        ];
        
        return [
            'steamid' => $apiData['steamid'] ?? '',
            'username' => $apiData['personaname'] ?? '未知用户',
            'realname' => $apiData['realname'] ?? '未公开',
            'profile_url' => $apiData['profileurl'] ?? '',
            'avatar' => $apiData['avatarfull'] ?? '',
            'status' => $statusMap[$apiData['personastate'] ?? 0] ?? '未知',
            'created_date' => $apiData['timecreated_str'] ?? '未知',
            'country' => $apiData['loccountrycode'] ?? '未知',
            'visibility' => $apiData['communityvisibilitystate'] == 3 ? '公开' : '私有',
            'version' => VERSION
        ];
    }
}

// === 主逻辑 ===
try {
    // 记录请求信息
    Logger::log("新请求", [
        'method' => $_SERVER['REQUEST_METHOD'],
        'params' => $_GET,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // 版本检测接口
    if (isset($_GET['version'])) {
        echo json_encode([
            'version' => VERSION,
            'build_date' => '2025-08-09',
            'support' => 'SteamID64 (17位数字)'
        ]);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('只支持GET请求');
    }
    
    if (!isset($_GET['steamid'])) {
        throw new Exception('缺少SteamID参数');
    }
    
    // 构建代理配置
    $proxyConfig = [
        'enable' => isset($_GET['proxy_enable']) && $_GET['proxy_enable'] === 'true',
        'server' => $_GET['proxy_server'] ?? '127.0.0.1',
        'port' => isset($_GET['proxy_port']) ? intval($_GET['proxy_port']) : 1080,
        'type' => $_GET['proxy_type'] ?? 'SOCKS5'
    ];
    
    // 获取并返回用户数据
    $result = SteamQuery::fetchUserData($_GET['steamid'], $proxyConfig);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $error = ['error' => $e->getMessage(), 'version' => VERSION];
    Logger::log("主逻辑异常", $error);
    echo json_encode($error);
}
?>