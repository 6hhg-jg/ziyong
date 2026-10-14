<?php
/**
 * TVBox 智能聚合代理
 * 功能：自动检测线路可用性，主线路失败时自动切换到备用线路
 * 使用：TVBox 配置地址填写 https://你的域名/tvbox.php
 */

// ==================== 浏览器访问时显示的图片 ====================
$COVER_IMAGE = 'http://xfyapk.com';

// 检测是否为浏览器直接访问
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 浏览器访问 → 返回图片；TVBox/爬虫访问 → 继续走 JSON
if (strpos($accept, 'text/html') !== false || preg_match('/(Chrome|Safari|Firefox|Edge|Trident)/i', $ua)) {
    header('Location: ' . $COVER_IMAGE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ==================== 配置区域 ====================
$LINES = [
    [
        'name' => '线路一',
        'url'  => 'https://v4.gh-proxy.org/https://raw.githubusercontent.com/6hhg-jg/ziyong/refs/heads/main/jbxa.json',
        'type' => 'primary'  // 主线路
    ],
    [
        'name' => '线路二',
        'url'  => 'http://zl.lli.asia/jbxa.json',
        'type' => 'fallback' // 备用线路
    ]
];

$CONFIG = [
    'timeout'      => 10,       // 单线路请求超时(秒)
    'max_retries'  => 2,        // 单线路最大重试次数
    'retry_delay'  => 2,        // 重试间隔(秒)
    'cache_file'   => __DIR__ . '/tvbox_cache.json',  // 缓存文件路径
    'cache_time'   => 0,        // 缓存有效期(秒)，0表示不缓存
    'user_agent'   => 'TVBox/1.0 (Auto-Switch Proxy)'
];

// ==================== 缓存操作 ====================
function getCache($file, $maxAge) {
    if ($maxAge <= 0 || !file_exists($file)) return null;
    if (time() - filemtime($file) > $maxAge) return null;
    $content = file_get_contents($file);
    if (!$content) return null;
    $data = json_decode($content, true);
    return $data ?: null;
}

function setCache($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ==================== 请求线路 ====================
function fetchLine($url, $timeout, $userAgent) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "CURL Error: $error", 'code' => 0];
    }

    if ($httpCode >= 200 && $httpCode < 300 && $response) {
        $json = json_decode($response, true);
        if ($json !== null) {
            return ['success' => true, 'data' => $json, 'code' => $httpCode];
        }
        return ['success' => false, 'error' => 'Invalid JSON response', 'code' => $httpCode];
    }

    return ['success' => false, 'error' => "HTTP $httpCode", 'code' => $httpCode];
}

// ==================== 带重试的请求 ====================
function fetchWithRetry($line, $config) {
    $url = $line['url'];

    for ($i = 0; $i <= $config['max_retries']; $i++) {
        if ($i > 0) {
            sleep($config['retry_delay']);
        }

        $result = fetchLine($url, $config['timeout'], $config['user_agent']);

        if ($result['success']) {
            return $result;
        }
    }

    return $result;
}

// ==================== 主逻辑 ====================
try {
    // 1. 尝试读取缓存
    $cached = getCache($CONFIG['cache_file'], $CONFIG['cache_time']);
    if ($cached && isset($cached['source']) && $cached['source'] === 'cache') {
        echo json_encode($cached['data'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lastError = null;
    $usedLine = null;
    $finalData = null;

    // 2. 依次尝试所有线路
    foreach ($LINES as $line) {
        $result = fetchWithRetry($line, $CONFIG);

        if ($result['success']) {
            $usedLine = $line;
            $finalData = $result['data'];
            break;
        }

        $lastError = "[{$line['name']}] {$result['error']}";
    }

    // 3. 处理结果
    if ($finalData === null) {
        http_response_code(503);
        echo json_encode([
            'error' => 'All lines unavailable',
            'message' => '所有线路均不可用，请稍后重试',
            'details' => $lastError,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. 添加代理信息到响应（可选，不影响 TVBox 使用）
    if (is_array($finalData)) {
        $finalData['_proxy_info'] = [
            'source_line' => $usedLine['name'],
            'source_url'  => $usedLine['url'],
            'proxy_time'  => date('Y-m-d H:i:s'),
            'auto_switch' => $usedLine['type'] === 'fallback'
        ];
    }

    // 5. 写入缓存
    if ($CONFIG['cache_time'] > 0) {
        setCache($CONFIG['cache_file'], [
            'source' => 'cache',
            'data' => $finalData,
            'time' => time()
        ]);
    }

    // 6. 输出 JSON
    echo json_encode($finalData, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
