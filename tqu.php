<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// 数据库连接信息
$servername = "127.0.0.1";
$username = "2024_153";
$password = "pXJjeQbncwn3fsnM";
$dbname = "2024_153";

// 创建连接
$conn = new mysqli($servername, $username, $password, $dbname);

// 检查连接
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => "连接失败: " . $conn->connect_error
    ]));
}

// 设置字符集
$conn->set_charset("utf8mb4");

try {
    $urlId = isset($_GET['urlId']) ? intval($_GET['urlId']) : 0;
    if ($urlId <= 0) {
        throw new Exception('无效的URL ID');
    }

    // 获取URL信息
    $sql = "SELECT urlname, url FROM seed WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $urlId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('URL不存在');
    }

    $urlInfo = $result->fetch_assoc();
    $url = $urlInfo['url'];
    
    // 提取网页内容
    $content = @file_get_contents($url);
    if ($content === false) {
        throw new Exception('无法访问URL');
    }

    $magnets = [];
    
    // 1. 匹配标准磁力链接（完整格式）
    if (preg_match_all('/magnet:\?xt=urn:btih:[0-9a-fA-F]{40}([&]|$)/i', $content, $matches)) {
        foreach ($matches[0] as $match) {
            if (preg_match('/btih:([0-9a-fA-F]{40})/i', $match, $hashMatch)) {
                $magnets[] = 'magnet:?xt=urn:btih:' . strtoupper($hashMatch[1]);
            }
        }
    }

    // 2. 匹配32位磁力链接
    if (preg_match_all('/magnet:\?xt=urn:btih:[0-9a-zA-Z]{32}([&]|$)/i', $content, $matches)) {
        foreach ($matches[0] as $match) {
            if (preg_match('/btih:([0-9a-zA-Z]{32})/i', $match, $hashMatch)) {
                $magnets[] = 'magnet:?xt=urn:btih:' . strtoupper($hashMatch[1]);
            }
        }
    }
    
    // 3. 匹配纯40位hash值
    if (preg_match_all('/\b[0-9a-fA-F]{40}\b/i', $content, $matches)) {
        foreach ($matches[0] as $match) {
            $magnets[] = 'magnet:?xt=urn:btih:' . strtoupper($match);
        }
    }
    
    // 4. 匹配纯32位hash值
    if (preg_match_all('/\b[0-9a-zA-Z]{32}\b/i', $content, $matches)) {
        foreach ($matches[0] as $match) {
            // 验证是否为有效的32位hash
            if (!preg_match('/[^0-9a-zA-Z]/', $match)) {
                $magnets[] = 'magnet:?xt=urn:btih:' . strtoupper($match);
            }
        }
    }

    // 去重
    $magnets = array_unique($magnets);
    
    if (empty($magnets)) {
        echo json_encode([
            'success' => true,
            'message' => '未找到磁力链接'
        ]);
        exit;
    }

    // 开始事务
    $conn->begin_transaction();
    
    try {
        $insertCount = 0;
        $totalCount = count($magnets);
        
        // 直接插入所有磁力链接到 seeds 表
        foreach ($magnets as $magnet) {
            $insertSql = "INSERT INTO seeds (urlname, me) VALUES (?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("ss", $urlInfo['urlname'], $magnet);
            if ($insertStmt->execute()) {
                $insertCount++;
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "发现 {$totalCount} 个磁力链接，已全部存入正式列表"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?> 