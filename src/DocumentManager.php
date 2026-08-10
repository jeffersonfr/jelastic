<?php

class DocumentManager {
    private $db;
    private $pdo;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    public function indexDocument($indexName, $id, array $data) {
        // Verificar se índice existe
        $metadata = $this->db->getIndexMetadata($indexName);
        if (!$metadata) {
            throw new Exception("Índice '{$indexName}' não encontrado");
        }

        $fields = json_decode($metadata['fields'], true);
        $ftsTable = $this->db->getFTSName($indexName);

        // Extrair campos para FTS
        $ftsData = [];
        foreach ($fields as $field) {
            $ftsData[$field] = $data[$field] ?? '';
        }

        // Salvar documento
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO documents (index_name, doc_id, data) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $indexName,
            $id,
            json_encode($data)
        ]);

        // Atualizar índice FTS
        $rowId = $this->pdo->lastInsertId();
        
        // Remover documento antigo do FTS se existir
        $stmt = $this->pdo->prepare("DELETE FROM {$ftsTable} WHERE rowid = ?");
        $stmt->execute([$rowId]);

        // Inserir novo documento no FTS
        $fieldsList = implode(', ', array_map(function($f) { return ":" . $f; }, $fields));
        $sql = "INSERT INTO {$ftsTable} (rowid, {$fieldsList}) VALUES (:rowid, {$fieldsList})";
        $stmt = $this->pdo->prepare($sql);
        
        $params = [':rowid' => $rowId];
        foreach ($fields as $field) {
            $params[":{$field}"] = $ftsData[$field];
        }
        $stmt->execute($params);

        // Atualizar contagem de documentos
        $stmt = $this->pdo->prepare("
            UPDATE indexes_metadata 
            SET document_count = (
                SELECT COUNT(*) FROM documents WHERE index_name = ?
            ) 
            WHERE name = ?
        ");
        $stmt->execute([$indexName, $indexName]);

        return [
            'status' => 'success',
            'message' => "Documento '{$id}' indexado com sucesso",
            'id' => $rowId
        ];
    }

    public function deleteDocument($indexName, $id) {
        $metadata = $this->db->getIndexMetadata($indexName);
        if (!$metadata) {
            throw new Exception("Índice '{$indexName}' não encontrado");
        }

        $ftsTable = $this->db->getFTSName($indexName);

        // Buscar documento
        $stmt = $this->pdo->prepare("SELECT id FROM documents WHERE index_name = ? AND doc_id = ?");
        $stmt->execute([$indexName, $id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc) {
            throw new Exception("Documento '{$id}' não encontrado");
        }

        // Remover do FTS
        $stmt = $this->pdo->prepare("DELETE FROM {$ftsTable} WHERE rowid = ?");
        $stmt->execute([$doc['id']]);

        // Remover da tabela de documentos
        $stmt = $this->pdo->prepare("DELETE FROM documents WHERE index_name = ? AND doc_id = ?");
        $stmt->execute([$indexName, $id]);

        // Atualizar contagem
        $stmt = $this->pdo->prepare("
            UPDATE indexes_metadata 
            SET document_count = (
                SELECT COUNT(*) FROM documents WHERE index_name = ?
            ) 
            WHERE name = ?
        ");
        $stmt->execute([$indexName, $indexName]);

        return [
            'status' => 'success',
            'message' => "Documento '{$id}' removido com sucesso"
        ];
    }

    public function getDocument($indexName, $id) {
        $stmt = $this->pdo->prepare("
            SELECT data, created_at, updated_at 
            FROM documents 
            WHERE index_name = ? AND doc_id = ?
        ");
        $stmt->execute([$indexName, $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Documento '{$id}' não encontrado");
        }
        
        $result['data'] = json_decode($result['data'], true);
        return $result;
    }

    public function bulkIndex($indexName, array $documents) {
        $results = [];
        foreach ($documents as $doc) {
            try {
                if (!isset($doc['id']) || !isset($doc['data'])) {
                    throw new Exception("Documento inválido: 'id' e 'data' são obrigatórios");
                }
                $result = $this->indexDocument($indexName, $doc['id'], $doc['data']);
                $results[] = $result;
            } catch (Exception $e) {
                $results[] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'id' => $doc['id'] ?? 'unknown'
                ];
            }
        }
        
        return [
            'status' => 'success',
            'message' => "Processados " . count($results) . " documentos",
            'results' => $results
        ];
    }
}
