#!/usr/bin/env php
<?php

/**
 * Worker para consumir mensagens do RabbitMQ
 * Uso: php workers/rabbitmq_consumer.php [config_id]
 */

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

// Carregar classes manualmente
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/DocumentManager.php';
require_once __DIR__ . '/../src/RabbitMQManager.php';

class RabbitMQConsumerWorker {
    private $db;
    private $pdo;
    private $rabbitMQManager;
    private $documentManager;
    private $running = true;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
        $this->rabbitMQManager = new RabbitMQManager();
        $this->documentManager = new DocumentManager();
    }

    public function run($configId = null) {
        echo "🐰 Iniciando Worker RabbitMQ Consumer (PHP Puro)...\n";
        echo "📅 " . date('Y-m-d H:i:s') . "\n";
        
        // Registrar signal handlers para PHP
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            echo "✅ Signal handlers registrados\n";
        } else {
            echo "⚠️  pcntl não disponível, use Ctrl+C para parar\n";
        }

        $cycle = 0;
        while ($this->running) {
            $cycle++;
            echo "\n🔄 Ciclo #{$cycle} - " . date('Y-m-d H:i:s') . "\n";
            
            try {
                // Buscar configurações ativas
                $configs = $this->getActiveConfigs($configId);
                
                if (empty($configs)) {
                    echo "⏳ Nenhuma configuração ativa. Aguardando 10 segundos...\n";
                    sleep(10);
                    continue;
                }

                foreach ($configs as $config) {
                    if (!$this->running) break;
                    
                    echo "📥 Processando configuração: {$config['name']} (ID: {$config['id']})\n";
                    
                    try {
                        // Consumir mensagens (máximo 5 por ciclo)
                        $result = $this->rabbitMQManager->consumeMessages(
                            $config['id'],
                            null,
                            5
                        );
                        
                        if ($result['status'] === 'success') {
                            $count = count($result['messages']);
                            echo "✅ Processadas {$count} mensagens\n";
                            
                            foreach ($result['messages'] as $msg) {
                                echo "   - {$msg['status']}: {$msg['message']}\n";
                            }
                        }
                    } catch (Exception $e) {
                        echo "❌ Erro ao processar {$config['name']}: " . $e->getMessage() . "\n";
                        
                        // Atualizar status da conexão
                        $this->db->updateRabbitMQConnectionStatus(
                            $config['id'],
                            'error',
                            $e->getMessage()
                        );
                        
                        sleep(5);
                    }
                }
                
                // Pequeno intervalo entre ciclos
                echo "⏳ Aguardando 3 segundos...\n";
                sleep(3);
                
            } catch (Exception $e) {
                echo "❌ Erro geral: " . $e->getMessage() . "\n";
                sleep(10);
            }
            
            // Processar signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        echo "\n👋 Worker finalizado em " . date('Y-m-d H:i:s') . "\n";
    }

    private function getActiveConfigs($configId = null) {
        if ($configId) {
            $config = $this->db->getRabbitMQConfig($configId);
            return $config ? [$config] : [];
        }
        
        $stmt = $this->pdo->query("
            SELECT * FROM rabbitmq_configs 
            WHERE status = 'active' 
            AND connection_type IN ('consumer', 'both')
            ORDER BY id
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function shutdown($signal) {
        echo "\n🛑 Recebido sinal de shutdown (" . $signal . ")\n";
        $this->running = false;
    }
}

// Executar worker
if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando\n");
}

// Verificar argumentos
$configId = $argv[1] ?? null;

try {
    $worker = new RabbitMQConsumerWorker();
    $worker->run($configId);
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
    exit(1);
}
