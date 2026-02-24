<?php
require_once '../config/db.php';

if (!isLoggedIn()) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($project_id > 0) {
    // Check if tasks table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'tasks'");
    if ($table_check->num_rows == 0) {
        echo json_encode([]);
        exit();
    }
    
    $stmt = $conn->prepare("
        SELECT id, task_name, description 
        FROM tasks 
        WHERE project_id = ? AND status = 'active' 
        ORDER BY task_name ASC
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $tasks[] = [
            'id' => $row['id'],
            'task_name' => $row['task_name'],
            'description' => $row['description']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($tasks);
    
    $stmt->close();
} else {
    echo json_encode([]);
}
?>