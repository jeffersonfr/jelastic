<?php

class Database {
    private static $instance = null;
    private $pdo;
    private $dbPath;

    private function __construct() {
        $this->dbPath = __DIR__ . '/../data/database.sqlite';
        $this->pdo = new PDO("sqlite:" . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        $this->pdo->exec("PRAGMA cache_size = 10000");
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function enableFTS5() {
        $this->pdo->exec("SELECT 1 FROM pragma_compile_options WHERE compile_options = 'ENABLE_FTS5'");
    }

    public function createTables() {
        // Tabela de metadados dos índices
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS indexes_metadata (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                fields TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                document_count INTEGER DEFAULT 0
            )
        ");

        // Tabela de documentos
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                index_name TEXT NOT NULL,
                doc_id TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(index_name, doc_id),
                FOREIGN KEY (index_name) REFERENCES indexes_metadata(name)
            )
        ");

        // NOVO: Tabela de configurações RabbitMQ
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rabbitmq_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                host TEXT NOT NULL,
                port INTEGER DEFAULT 5672,
                vhost TEXT DEFAULT '/',
                username TEXT NOT NULL,
                password TEXT NOT NULL,
                exchange TEXT,
                queue TEXT NOT NULL,
                routing_key TEXT,
                connection_type TEXT DEFAULT 'producer', -- 'producer', 'consumer', 'both'
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_connected DATETIME,
                error_message TEXT
            )
        ");

        // NOVO: Tabela de mensagens processadas
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rabbitmq_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_id INTEGER NOT NULL,
                message_id TEXT,
                exchange TEXT,
                routing_key TEXT,
                data TEXT NOT NULL,
                status TEXT DEFAULT 'pending', -- 'pending', 'processed', 'failed'
                target_index TEXT,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME,
                FOREIGN KEY (config_id) REFERENCES rabbitmq_configs(id)
            )
        ");

        // Log de operações
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS operation_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                operation TEXT NOT NULL,
                index_name TEXT,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Triggers
        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS update_documents_updated_at 
            AFTER UPDATE ON documents
            BEGIN
                UPDATE documents SET updated_at = CURRENT_TIMESTAMP 
                WHERE id = NEW.id;
            END
        ");

        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS update_rabbitmq_configs_updated_at 
            AFTER UPDATE ON rabbitmq_configs
            BEGIN
                UPDATE rabbitmq_configs SET updated_at = CURRENT_TIMESTAMP 
                WHERE id = NEW.id;
            END
        ");
    }

    public function createFTSIndex($indexName, $fields) {
        $fieldsList = array_map(function($field) {
            return preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        }, $fields);
        
        $fieldsStr = implode(', ', $fieldsList);
        $ftsTable = $this->getFTSName($indexName);
        
        $sql = "
            CREATE VIRTUAL TABLE IF NOT EXISTS {$ftsTable} USING fts5(
                {$fieldsStr},
                tokenize='porter unicode61'
            )
        ";
        
        $this->pdo->exec($sql);
        
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO indexes_metadata (name, fields) 
            VALUES (?, ?)
        ");
        $stmt->execute([$indexName, json_encode($fields)]);
        
        return true;
    }

    public function getFTSName($indexName) {
        return 'fts_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $indexName);
    }

    public function getIndexMetadata($indexName) {
        $stmt = $this->pdo->prepare("SELECT * FROM indexes_metadata WHERE name = ?");
        $stmt->execute([$indexName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllIndexes() {
        $stmt = $this->pdo->query("SELECT * FROM indexes_metadata ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteIndex($indexName) {
        $ftsTable = $this->getFTSName($indexName);
        $this->pdo->exec("DROP TABLE IF EXISTS {$ftsTable}");
        
        $stmt = $this->pdo->prepare("DELETE FROM documents WHERE index_name = ?");
        $stmt->execute([$indexName]);
        
        $stmt = $this->pdo->prepare("DELETE FROM indexes_metadata WHERE name = ?");
        $stmt->execute([$indexName]);
        
        return true;
    }

    // Métodos RabbitMQ
    public function saveRabbitMQConfig($data) {
        if (isset($data['id']) && $data['id']) {
            $sql = "
                UPDATE rabbitmq_configs 
                SET name = ?, host = ?, port = ?, vhost = ?, username = ?, 
                    password = ?, exchange = ?, queue = ?, routing_key = ?,
                    connection_type = ?, status = ?
                WHERE id = ?
            ";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                $data['name'], $data['host'], $data['port'], $data['vhost'] ?? '/',
                $data['username'], $data['password'], $data['exchange'] ?? null,
                $data['queue'], $data['routing_key'] ?? null,
                $data['connection_type'] ?? 'producer',
                $data['status'] ?? 'active',
                $data['id']
            ]);
        } else {
            $sql = "
                INSERT INTO rabbitmq_configs 
                (name, host, port, vhost, username, password, exchange, queue, routing_key, connection_type, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                $data['name'], $data['host'], $data['port'], $data['vhost'] ?? '/',
                $data['username'], $data['password'], $data['exchange'] ?? null,
                $data['queue'], $data['routing_key'] ?? null,
                $data['connection_type'] ?? 'producer',
                $data['status'] ?? 'active'
            ]);
        }
    }

    public function getRabbitMQConfig($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM rabbitmq_configs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllRabbitMQConfigs() {
        $stmt = $this->pdo->query("SELECT * FROM rabbitmq_configs ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteRabbitMQConfig($id) {
        $stmt = $this->pdo->prepare("DELETE FROM rabbitmq_configs WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function updateRabbitMQConnectionStatus($id, $status, $error = null) {
        $stmt = $this->pdo->prepare("
            UPDATE rabbitmq_configs 
            SET status = ?, last_connected = CURRENT_TIMESTAMP, error_message = ?
            WHERE id = ?
        ");
        return $stmt->execute([$status, $error, $id]);
    }

    public function saveRabbitMQMessage($configId, $messageId, $data, $exchange, $routingKey, $targetIndex = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO rabbitmq_messages 
            (config_id, message_id, exchange, routing_key, data, target_index)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $configId, 
            $messageId, 
            $exchange, 
            $routingKey, 
            json_encode($data), 
            $targetIndex
        ]);
    }

    public function updateRabbitMQMessageStatus($messageId, $status, $error = null) {
        $stmt = $this->pdo->prepare("
            UPDATE rabbitmq_messages 
            SET status = ?, processed_at = CURRENT_TIMESTAMP, error = ?
            WHERE message_id = ?
        ");
        return $stmt->execute([$status, $error, $messageId]);
    }

    public function getRabbitMQMessages($configId = null, $status = null, $limit = 100) {
        $sql = "SELECT * FROM rabbitmq_messages WHERE 1=1";
        $params = [];
        
        if ($configId) {
            $sql .= " AND config_id = ?";
            $params[] = $configId;
        }
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function logOperation($operation, $indexName, $details) {
        $stmt = $this->pdo->prepare("
            INSERT INTO operation_log (operation, index_name, details) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$operation, $indexName, $details]);
    }
}
