<?php

class IndexManager {
    private $db;
    private $pdo;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    public function createIndex($name, array $fields) {
        // Validar nome do índice
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new Exception("Nome do índice inválido. Use apenas letras, números e underscore.");
        }

        if (empty($fields)) {
            throw new Exception("É necessário definir pelo menos um campo.");
        }

        // Verificar se já existe
        $stmt = $this->pdo->prepare("SELECT name FROM indexes_metadata WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            throw new Exception("Índice '{$name}' já existe.");
        }

        // Criar índice FTS
        $this->db->createFTSIndex($name, $fields);
        
        // Registrar log
        $this->logOperation('create_index', $name, "Fields: " . implode(', ', $fields));
        
        return [
            'status' => 'success',
            'message' => "Índice '{$name}' criado com sucesso",
            'fields' => $fields
        ];
    }

    public function listIndexes() {
        $indexes = $this->db->getAllIndexes();
        
        // Adicionar contagem de documentos
        foreach ($indexes as &$index) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as total FROM documents WHERE index_name = ?");
            $stmt->execute([$index['name']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $index['document_count'] = (int)$result['total'];
            
            $index['fields'] = json_decode($index['fields'], true);
        }
        
        return $indexes;
    }

    public function getIndex($name) {
        $metadata = $this->db->getIndexMetadata($name);
        if (!$metadata) {
            throw new Exception("Índice '{$name}' não encontrado");
        }
        
        $metadata['fields'] = json_decode($metadata['fields'], true);
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as total FROM documents WHERE index_name = ?");
        $stmt->execute([$name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metadata['document_count'] = (int)$result['total'];
        
        return $metadata;
    }

    public function deleteIndex($name) {
        $metadata = $this->db->getIndexMetadata($name);
        if (!$metadata) {
            throw new Exception("Índice '{$name}' não encontrado");
        }
        
        $this->db->deleteIndex($name);
        $this->logOperation('delete_index', $name, "Índice removido");
        
        return [
            'status' => 'success',
            'message' => "Índice '{$name}' removido com sucesso"
        ];
    }

    private function logOperation($operation, $indexName, $details) {
        $stmt = $this->pdo->prepare("
            INSERT INTO operation_log (operation, index_name, details) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$operation, $indexName, $details]);
    }
}
