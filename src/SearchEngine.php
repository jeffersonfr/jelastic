<?php

class SearchEngine {
    private $db;
    private $pdo;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    public function search($indexName, $query, array $options = []) {
        $metadata = $this->db->getIndexMetadata($indexName);
        if (!$metadata) {
            throw new Exception("Índice '{$indexName}' não encontrado");
        }

        $fields = json_decode($metadata['fields'], true);
        $ftsTable = $this->db->getFTSName($indexName);
        
        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;
        $sortBy = $options['sort_by'] ?? 'score';
        $sortOrder = $options['sort_order'] ?? 'DESC';

        // Construir query de busca
        $searchQuery = $this->buildSearchQuery($query, $options);
        
        // Buscar documentos
        $sql = "
            SELECT 
                d.doc_id,
                d.data,
                d.created_at,
                d.updated_at,
                {$ftsTable}.rank as score
            FROM {$ftsTable}
            INNER JOIN documents d ON d.id = {$ftsTable}.rowid
            WHERE {$ftsTable} MATCH :query
            AND d.index_name = :index_name
        ";

        // Adicionar ordenação
        if ($sortBy === 'score') {
            $sql .= " ORDER BY rank " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
        } else {
            // Ordenação por campo do documento
            $sql .= " ORDER BY json_extract(d.data, '$.{$sortBy}') {$sortOrder}";
        }

        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':query', $searchQuery);
        $stmt->bindValue(':index_name', $indexName);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Processar resultados
        $hits = [];
        foreach ($results as $result) {
            $data = json_decode($result['data'], true);
            $hits[] = [
                'id' => $result['doc_id'],
                'score' => (float)$result['score'],
                'data' => $data,
                'created_at' => $result['created_at'],
                'updated_at' => $result['updated_at']
            ];
        }

        // Contar total
        $countSql = "
            SELECT COUNT(*) as total
            FROM {$ftsTable}
            WHERE {$ftsTable} MATCH :query
            AND rowid IN (SELECT id FROM documents WHERE index_name = :index_name)
        ";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->bindValue(':query', $searchQuery);
        $countStmt->bindValue(':index_name', $indexName);
        $countStmt->execute();
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        return [
            'status' => 'success',
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'hits' => $hits,
            'query' => $searchQuery
        ];
    }

    private function buildSearchQuery($query, $options) {
        // Suporte a operadores básicos
        $query = trim($query);
        
        // Se tiver operadores, usar como está
        if (preg_match('/\b(AND|OR|NOT)\b/i', $query) || strpos($query, '"') !== false) {
            return $query;
        }

        // Busca por frases entre aspas
        if (preg_match('/"([^"]+)"/', $query, $matches)) {
            return $query;
        }

        // Busca simples com operador AND implícito
        $terms = preg_split('/\s+/', $query);
        if (count($terms) > 1) {
            return implode(' AND ', $terms);
        }

        // Busca com prefixo (autocomplete)
        if ($options['prefix'] ?? false) {
            return $query . '*';
        }

        return $query;
    }

    public function getSuggestions($indexName, $prefix, $limit = 10) {
        // Sugestões baseadas em termos existentes
        $metadata = $this->db->getIndexMetadata($indexName);
        if (!$metadata) {
            throw new Exception("Índice '{$indexName}' não encontrado");
        }

        $fields = json_decode($metadata['fields'], true);
        $ftsTable = $this->db->getFTSName($indexName);

        // Buscar termos que começam com o prefixo
        $sql = "
            SELECT DISTINCT 
                json_extract(d.data, '$." . $fields[0] . "') as value
            FROM {$ftsTable}
            INNER JOIN documents d ON d.id = {$ftsTable}.rowid
            WHERE d.index_name = :index_name
            AND {$ftsTable} MATCH :prefix
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':prefix', $prefix . '*');
        $stmt->bindValue(':index_name', $indexName);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
