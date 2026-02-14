<?php

declare(strict_types=1);

header('Content-Type: application/json');

try {
    // Test 1: Can we load autoload?
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('vendor/autoload.php does not exist');
    }

    require_once __DIR__ . '/vendor/autoload.php';

    // Test 2: Can we connect to the database?
    use App\Database;

    $db = Database::getInstance();
    
    // Test 3: Can we query the database?
    $stmt = $db->query("SELECT 1");
    $result = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'All systems operational',
        'test_result' => $result
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
