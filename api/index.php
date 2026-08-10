<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Autoload simples
spl_autoload_register(function($class) {
    $file = __DIR__ . '/../src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Inicializar banco
$db = Database::getInstance();
$db->createTables();

// Rotas
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';
$path = rtrim($path, '/');

// Parsear entrada JSON
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch (true) {
        case $method === 'GET' && $path === '/indexes':
            // Listar índices
            $manager = new IndexManager();
            echo json_encode($manager->listIndexes());
            break;

        case $method === 'POST' && $path === '/indexes':
            // Criar índice
            $manager = new IndexManager();
            if (!isset($input['name']) || !isset($input['fields'])) {
                throw new Exception("'name' e 'fields' são obrigatórios");
            }
            echo json_encode($manager->createIndex($input['name'], $input['fields']));
            break;

        case $method === 'DELETE' && preg_match('/^\/indexes\/([^\/]+)$/', $path, $matches):
            // Remover índice
            $manager = new IndexManager();
            echo json_encode($manager->deleteIndex($matches[1]));
            break;

        case $method === 'GET' && preg_match('/^\/indexes\/([^\/]+)$/', $path, $matches):
            // Obter informações do índice
            $manager = new IndexManager();
            echo json_encode($manager->getIndex($matches[1]));
            break;

        case $method === 'POST' && preg_match('/^\/indexes\/([^\/]+)\/documents$/', $path, $matches):
            // Indexar documento
            $manager = new DocumentManager();
            if (!isset($input['id']) || !isset($input['data'])) {
                throw new Exception("'id' e 'data' são obrigatórios");
            }
            echo json_encode($manager->indexDocument($matches[1], $input['id'], $input['data']));
            break;

        case $method === 'POST' && preg_match('/^\/indexes\/([^\/]+)\/documents\/_bulk$/', $path, $matches):
            // Indexar em lote
            $manager = new DocumentManager();
            if (!isset($input['documents'])) {
                throw new Exception("'documents' é obrigatório");
            }
            echo json_encode($manager->bulkIndex($matches[1], $input['documents']));
            break;

        case $method === 'DELETE' && preg_match('/^\/indexes\/([^\/]+)\/documents\/([^\/]+)$/', $path, $matches):
            // Remover documento
            $manager = new DocumentManager();
            echo json_encode($manager->deleteDocument($matches[1], $matches[2]));
            break;

        case $method === 'GET' && preg_match('/^\/indexes\/([^\/]+)\/documents\/([^\/]+)$/', $path, $matches):
            // Obter documento
            $manager = new DocumentManager();
            echo json_encode($manager->getDocument($matches[1], $matches[2]));
            break;

        case $method === 'GET' && preg_match('/^\/search\/([^\/]+)$/', $path, $matches):
            // Buscar
            $search = new SearchEngine();
            $query = $_GET['q'] ?? '';
            if (empty($query)) {
                throw new Exception("Parâmetro 'q' é obrigatório");
            }
            $options = [
                'limit' => (int)($_GET['limit'] ?? 20),
                'offset' => (int)($_GET['offset'] ?? 0),
                'sort_by' => $_GET['sort_by'] ?? 'score',
                'sort_order' => $_GET['sort_order'] ?? 'DESC',
                'prefix' => isset($_GET['prefix'])
            ];
            echo json_encode($search->search($matches[1], $query, $options));
            break;

        case $method === 'GET' && preg_match('/^\/suggestions\/([^\/]+)$/', $path, $matches):
            // Sugestões
            $search = new SearchEngine();
            $prefix = $_GET['q'] ?? '';
            if (empty($prefix)) {
                throw new Exception("Parâmetro 'q' é obrigatório");
            }
            $limit = (int)($_GET['limit'] ?? 10);
            echo json_encode($search->getSuggestions($matches[1], $prefix, $limit));
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Rota não encontrada']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
