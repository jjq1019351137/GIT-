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
    // 开始事务
    $conn->begin_transaction();

    // 1. 创建临时表存储所有不重复的记录
    $sql = "
        CREATE TEMPORARY TABLE temp_unique AS
        SELECT MIN(id) as id
        FROM seeds
        GROUP BY me";

    $conn->query($sql);

    // 2. 删除所有不在临时表中的记录（即重复记录）
    $deleteSql = "
        DELETE FROM seeds 
        WHERE id NOT IN (SELECT id FROM temp_unique)";

    $conn->query($deleteSql);

    // 3. 获取删除的记录数
    $affectedRows = $conn->affected_rows;

    // 4. 删除临时表
    $conn->query("DROP TEMPORARY TABLE temp_unique");

    // 提交事务
    $conn->commit();

    // 返回结果
    if ($affectedRows > 0) {
        $message = "成功删除 {$affectedRows} 条重复记录";
    } else {
        $message = "没有发现重复的磁力链接";
    }

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    // 发生错误时回滚事务
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
