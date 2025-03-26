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
    if ($action === 'clearAll') {
        // 清空临时列表
        try {
            // 获取当前记录数
            $countSql = "SELECT COUNT(*) as count FROM seednow";
            $countResult = $conn->query($countSql);
            $count = $countResult->fetch_assoc()['count'];
            
            if ($count > 0) {
                // 清空 seednow 表
                $sql = "TRUNCATE TABLE seednow";
                if ($conn->query($sql)) {
                    echo json_encode([
                        'success' => true,
                        'message' => "成功清空 {$count} 条临时记录"
                    ]);
                } else {
                    throw new Exception('清空失败');
                }
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => '临时列表已经是空的'
                ]);
            }
        } catch (Exception $e) {
            throw $e;
        }
    } else if ($action === 'saveSeed') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($id <= 0) {
            throw new Exception('无效的种子ID');
        }

        $conn->begin_transaction();
        
        try {
            // 1. 获取要保存的种子信息
            $sql = "SELECT urlname, me FROM seednow WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('种子不存在');
            }

            $seedInfo = $result->fetch_assoc();
            
            // 2. 插入到 seeds 表
            $insertSql = "INSERT INTO seeds (urlname, me) VALUES (?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("ss", $seedInfo['urlname'], $seedInfo['me']);
            $insertStmt->execute();
            
            // 3. 从 seednow 表中删除
            $deleteSql = "DELETE FROM seednow WHERE id = ?";
            $deleteStmt = $conn->prepare($deleteSql);
            $deleteStmt->bind_param("i", $id);
            $deleteStmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => '种子已成功保存到正式列表'
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } else if ($action === 'copySeed') {
        $id = $_GET['id'];
        
        // 这里可以添加记录复制操作的代码
        // 例如：记录到日志、更新数据库等
        
        echo json_encode([
            'success' => true,
            'message' => '复制操作已记录'
        ]);
        exit;
    } else {
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