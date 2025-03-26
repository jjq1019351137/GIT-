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

// 获取操作类型
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($action) {
        case 'refresh':
            // 刷新指定URL的种子
            $urlId = isset($_GET['urlId']) ? intval($_GET['urlId']) : 0;
            if ($urlId <= 0) {
                throw new Exception('无效的URL ID');
            }

            // 获取URL信息
            $sql = "SELECT id, urlname, url, nb FROM seed WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $urlId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('URL不存在');
            }

            $urlInfo = $result->fetch_assoc();
            $url = $urlInfo['url'];
            
            // 提取磁力链接
            $content = @file_get_contents($url);
            if ($content === false) {
                throw new Exception('无法访问URL');
            }

            $magnets = [];
            
            // 匹配磁力链接
            if (preg_match_all('/magnet:\?xt=urn:btih:[0-9a-zA-Z]{32,40}(?:&[^&]*)*/', $content, $matches)) {
                foreach ($matches[0] as $match) {
                    if (preg_match('/btih:([0-9a-zA-Z]{32,40})/i', $match, $hashMatch)) {
                        $magnets[] = 'magnet:?xt=urn:btih:' . strtoupper($hashMatch[1]);
                    }
                }
            }
            
            // 匹配纯hash值
            if (preg_match_all('/[0-9a-fA-F]{40}|[0-9a-zA-Z]{32}/', $content, $matches)) {
                foreach ($matches[0] as $match) {
                    if (preg_match('/^[0-9a-fA-F]{40}$/i', $match) || preg_match('/^[0-9a-zA-Z]{32}$/i', $match)) {
                        $magnets[] = 'magnet:?xt=urn:btih:' . strtoupper($match);
                    }
                }
            }

            $magnets = array_unique($magnets);
            
            if (empty($magnets)) {
                echo json_encode([
                    'success' => true,
                    'message' => '未找到磁力链接'
                ]);
                break;
            }

            // 获取已存在的磁力链接
            $existingSql = "SELECT me FROM seeds";
            $existingResult = $conn->query($existingSql);
            $existingMagnets = [];
            while ($row = $existingResult->fetch_assoc()) {
                $existingMagnets[] = $row['me'];
            }

            // 找出新的磁力链接
            $newMagnets = array_diff($magnets, $existingMagnets);
            $newCount = 0;

            if (!empty($newMagnets)) {
                $conn->begin_transaction();
                
                try {
                    foreach ($newMagnets as $magnet) {
                        // 检查是否已在 seednow 表中存在
                        $checkSql = "SELECT COUNT(*) as count FROM seednow WHERE me = ?";
                        $checkStmt = $conn->prepare($checkSql);
                        $checkStmt->bind_param("s", $magnet);
                        $checkStmt->execute();
                        $exists = $checkStmt->get_result()->fetch_assoc()['count'] > 0;
                        
                        if (!$exists) {
                            // 插入到 seednow 表
                            $insertSql = "INSERT INTO seednow (urlname, nb, me) VALUES (?, ?, ?)";
                            $insertStmt = $conn->prepare($insertSql);
                            $insertStmt->bind_param("sss", $urlInfo['urlname'], $urlInfo['nb'], $magnet);
                            if ($insertStmt->execute()) {
                                $newCount++;
                            }
                        }
                    }
                    
                    $conn->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => "发现 " . count($magnets) . " 个磁力链接，新增 {$newCount} 个到临时列表"
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    throw $e;
                }
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => '没有发现新的磁力链接'
                ]);
            }
            break;

        case 'getSeeds':
            // 获取分页参数
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 5;
            
            // 计算偏移量
            $offset = ($page - 1) * $pageSize;
            
            // 获取总记录数
            $countSql = "SELECT COUNT(*) as total FROM seednow";
            $countResult = $conn->query($countSql);
            $totalRecords = $countResult->fetch_assoc()['total'];
            
            // 计算总页数
            $totalPages = ceil($totalRecords / $pageSize);
            
            // 获取当前页的数据
            $sql = "SELECT s.*, NOT EXISTS(SELECT 1 FROM seeds WHERE seeds.me = s.me) as isNew 
                   FROM seednow s ORDER BY s.id DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $pageSize, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $seeds = [];
            while ($row = $result->fetch_assoc()) {
                $row['isNew'] = (bool)$row['isNew'];
                $seeds[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'seeds' => $seeds,
                'totalPages' => $totalPages,
                'currentPage' => $page
            ]);
            break;

        case 'saveSeed':
            // 保存种子到正式列表
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id <= 0) {
                throw new Exception('无效的种子ID');
            }

            $conn->begin_transaction();
            
            try {
                // 获取种子信息
                $sql = "SELECT * FROM seednow WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception('种子不存在');
                }

                $seed = $result->fetch_assoc();

                // 检查是否已存在于正式列表
                $checkSql = "SELECT COUNT(*) as count FROM seeds WHERE me = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("s", $seed['me']);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->fetch_assoc()['count'] > 0;

                if (!$exists) {
                    // 插入到正式列表
                    $insertSql = "INSERT INTO seeds (urlname, urlname_id, me) VALUES (?, ?, ?)";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param("sis", $seed['urlname'], $seed['urlname_id'], $seed['me']);
                    $insertStmt->execute();
                }

                // 从临时列表删除
                $deleteSql = "DELETE FROM seednow WHERE id = ?";
                $deleteStmt = $conn->prepare($deleteSql);
                $deleteStmt->bind_param("i", $id);
                $deleteStmt->execute();

                $conn->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'deleteSeed':
            // 从临时列表删除种子
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id <= 0) {
                throw new Exception('无效的种子ID');
            }

            $sql = "DELETE FROM seednow WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                throw new Exception('删除失败');
            }
            break;

        default:
            throw new Exception('未知的操作类型');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?> 