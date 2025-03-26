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
        case 'getUrls':
            // 获取分页参数
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 5;
            $nb = isset($_GET['nb']) ? $_GET['nb'] : '';
            
            // 计算偏移量
            $offset = ($page - 1) * $pageSize;
            
            // 获取总记录数
            $countSql = "SELECT COUNT(*) as total FROM seed";
            if ($nb !== '') {
                $countSql .= " WHERE nb = ?";
            }
            
            $countStmt = $conn->prepare($countSql);
            if ($nb !== '') {
                $countStmt->bind_param("s", $nb);
            }
            $countStmt->execute();
            $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
            
            // 计算总页数
            $totalPages = ceil($totalRecords / $pageSize);
            
            // 获取当前页的数据
            $sql = "SELECT * FROM seed";
            if ($nb !== '') {
                $sql .= " WHERE nb = ?";
            }
            $sql .= " ORDER BY sort_order ASC, id ASC LIMIT ? OFFSET ?";

            $stmt = $conn->prepare($sql);
            if ($nb !== '') {
                $stmt->bind_param("sii", $nb, $pageSize, $offset);
            } else {
                $stmt->bind_param("ii", $pageSize, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $urls = [];
            while ($row = $result->fetch_assoc()) {
                $urls[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'urls' => $urls,
                'totalPages' => $totalPages,
                'currentPage' => $page
            ]);
            break;

        case 'addUrl':
            // 添加新URL
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['urlname']) || empty($data['url'])) {
                throw new Exception('URL名称和地址不能为空');
            }

            // 获取最大的sort_order
            $maxOrderSql = "SELECT MAX(sort_order) as max_order FROM seed";
            $maxOrderResult = $conn->query($maxOrderSql);
            $maxOrder = $maxOrderResult->fetch_assoc()['max_order'] ?? 0;
            $newOrder = $maxOrder + 1;

            $sql = "INSERT INTO seed (urlname, url, nb, sort_order) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $data['urlname'], $data['url'], $data['nb'], $newOrder);
            
            if (!$stmt->execute()) {
                throw new Exception('添加URL失败');
            }
            echo json_encode(['success' => true]);
            break;

        case 'rename':
            // 重命名URL
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['urlname'])) {
                throw new Exception('URL名称不能为空');
            }

            $sql = "UPDATE seed SET urlname = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $data['urlname'], $data['id']);
            
            if (!$stmt->execute()) {
                throw new Exception('重命名失败');
            }
            echo json_encode(['success' => true]);
            break;

        case 'updateTiam':
            // 更新时间值
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                throw new Exception('参数错误');
            }

            $tiam = $data['tiam'] === '' ? '0' : $data['tiam'];
            $sql = "UPDATE seed SET tiam = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $tiam, $data['id']);
            
            if (!$stmt->execute()) {
                throw new Exception('更新时间值失败');
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            // 删除URL
            $data = json_decode(file_get_contents('php://input'), true);
            $conn->begin_transaction();

            try {
                // 删除相关的 seeds 记录
                $deleteSeedsSQL = "DELETE FROM seeds WHERE urlname = ?";
                $seedsStmt = $conn->prepare($deleteSeedsSQL);
                $seedsStmt->bind_param("s", $data['urlname']);
                $seedsStmt->execute();

                // 删除相关的 seednow 记录
                $deleteSeednowSQL = "DELETE FROM seednow WHERE urlname = ?";
                $seednowStmt = $conn->prepare($deleteSeednowSQL);
                $seednowStmt->bind_param("s", $data['urlname']);
                $seednowStmt->execute();

                // 删除 URL
                $deleteSeedSQL = "DELETE FROM seed WHERE id = ?";
                $seedStmt = $conn->prepare($deleteSeedSQL);
                $seedStmt->bind_param("i", $data['id']);
                $seedStmt->execute();

                $conn->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'updateOrder':
            // 更新排序
            $data = json_decode(file_get_contents('php://input'), true);
            $conn->begin_transaction();

            try {
                foreach ($data as $item) {
                    $sql = "UPDATE seed SET sort_order = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ii", $item['sort_order'], $item['id']);
                    $stmt->execute();
                }

                $conn->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'updateNb':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                throw new Exception('参数错误');
            }

            $sql = "UPDATE seed SET nb = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $data['nb'], $data['id']);
            
            if (!$stmt->execute()) {
                throw new Exception('更新类型失败');
            }
            echo json_encode(['success' => true]);
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