<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Autoload manual
spl_autoload_register(function($class) {
    $paths = [
        __DIR__ . '/../src/' . $class . '.php',
        __DIR__ . '/../libs/RabbitMQ/' . $class . '.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return true;
        }
    }
    return false;
});

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/DocumentManager.php';
require_once __DIR__ . '/../src/RabbitMQManager.php';

$db = Database::getInstance();
$db->createTables();
$rabbitMQManager = new RabbitMQManager();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';
$path = rtrim($path, '/');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch (true) {
        // Listar configurações
        case $method === 'GET' && $path === '/configs':
            echo json_encode($rabbitMQManager->getConfigs());
            break;

        // Salvar configuração
        case $method === 'POST' && $path === '/configs':
            echo json_encode($rabbitMQManager->testAndSave($input));
            break;

        // Atualizar configuração
        case $method === 'PUT' && preg_match('/^\/configs\/(\d+)$/', $path, $matches):
            $input['id'] = $matches[1];
            echo json_encode($rabbitMQManager->saveConfig($input));
            break;

        // Remover configuração
        case $method === 'DELETE' && preg_match('/^\/configs\/(\d+)$/', $path, $matches):
            echo json_encode($rabbitMQManager->deleteConfig($matches[1]));
            break;

        // Testar conexão
        case $method === 'POST' && preg_match('/^\/configs\/(\d+)\/test$/', $path, $matches):
            echo json_encode($rabbitMQManager->testConnection($matches[1]));
            break;

        // Publicar mensagem
        case $method === 'POST' && preg_match('/^\/configs\/(\d+)\/publish$/', $path, $matches):
            if (!isset($input['message'])) {
                throw new Exception("Campo 'message' é obrigatório");
            }
            $routingKey = $input['routing_key'] ?? null;
            echo json_encode($rabbitMQManager->publishMessage($matches[1], $input['message'], $routingKey));
            break;

        // Consumir mensagens
        case $method === 'POST' && preg_match('/^\/configs\/(\d+)\/consume$/', $path, $matches):
            $limit = $input['limit'] ?? 10;
            echo json_encode($rabbitMQManager->consumeMessages($matches[1], null, $limit));
            break;

        // Listar mensagens processadas
        case $method === 'GET' && $path === '/messages':
            $configId = $_GET['config_id'] ?? null;
            $status = $_GET['status'] ?? null;
            $limit = (int)($_GET['limit'] ?? 100);
            echo json_encode($rabbitMQManager->getMessages($configId, $status, $limit));
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Rota não encontrada']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
