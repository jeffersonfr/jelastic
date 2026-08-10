<?php

require_once __DIR__ . '/../libs/RabbitMQ/AMQP.php';
require_once __DIR__ . '/../libs/RabbitMQ/Channel.php';
require_once __DIR__ . '/../libs/RabbitMQ/Message.php';

use RabbitMQ\AMQP;

class RabbitMQManager {
    private $db;
    private $pdo;
    private $connections = [];
    private $documentManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
        $this->documentManager = new DocumentManager();
    }

    private function getConnection($config) {
        $key = $config['id'] . '_' . $config['host'] . '_' . $config['port'];
        
        if (!isset($this->connections[$key])) {
            try {
                $amqp = new AMQP();
                $amqp->connect(
                    $config['host'],
                    $config['port'],
                    $config['vhost'] ?? '/',
                    $config['username'],
                    $config['password']
                );
                $this->connections[$key] = $amqp;
                $this->db->updateRabbitMQConnectionStatus($config['id'], 'connected');
            } catch (Exception $e) {
                $this->db->updateRabbitMQConnectionStatus($config['id'], 'error', $e->getMessage());
                throw $e;
            }
        }
        
        return $this->connections[$key];
    }

    public function testConnection($configId) {
        $config = $this->db->getRabbitMQConfig($configId);
        if (!$config) {
            throw new Exception("Configuração não encontrada");
        }

        try {
            $connection = $this->getConnection($config);
            $channel = $connection->channel();
            
            // Testar conexão declarando uma queue temporária
            $channel->queueDeclare($config['queue'], false, true, false, true);
            $channel->close();
            
            return [
                'status' => 'success',
                'message' => 'Conexão estabelecida com sucesso',
                'details' => [
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'vhost' => $config['vhost'],
                    'queue' => $config['queue']
                ]
            ];
        } catch (Exception $e) {
            $this->db->updateRabbitMQConnectionStatus($configId, 'error', $e->getMessage());
            throw new Exception("Falha na conexão: " . $e->getMessage());
        }
    }

    public function publishMessage($configId, $message, $routingKey = null) {
        $config = $this->db->getRabbitMQConfig($configId);
        if (!$config) {
            throw new Exception("Configuração não encontrada");
        }

        try {
            $connection = $this->getConnection($config);
            $channel = $connection->channel();

            // Declarar exchange se especificada
            if ($config['exchange']) {
                $channel->exchangeDeclare(
                    $config['exchange'],
                    'topic',
                    false,
                    true,
                    false
                );
            }

            // Declarar queue
            $channel->queueDeclare(
                $config['queue'],
                false,
                true,
                false,
                false
            );

            // Bind queue to exchange
            if ($config['exchange']) {
                $channel->queueBind(
                    $config['queue'],
                    $config['exchange'],
                    $routingKey ?? $config['routing_key'] ?? ''
                );
            }

            // Preparar mensagem
            $msgData = is_array($message) ? json_encode($message) : $message;
            
            // Propriedades da mensagem
            $properties = [
                'delivery_mode' => 2, // Persistent
                'content_type' => 'application/json',
                'message_id' => uniqid('msg_', true)
            ];

            // Publicar
            $channel->basicPublish(
                $config['exchange'] ?? '',
                $routingKey ?? $config['routing_key'] ?? $config['queue'],
                $msgData,
                $properties
            );

            // Salvar no banco
            $messageId = $properties['message_id'];
            $this->db->saveRabbitMQMessage(
                $configId,
                $messageId,
                $message,
                $config['exchange'] ?? '',
                $routingKey ?? $config['routing_key'] ?? '',
                $message['_index'] ?? null
            );

            $channel->close();
            
            return [
                'status' => 'success',
                'message' => 'Mensagem publicada com sucesso',
                'message_id' => $messageId
            ];
        } catch (Exception $e) {
            throw new Exception("Erro ao publicar mensagem: " . $e->getMessage());
        }
    }

    public function consumeMessages($configId, $callback = null, $limit = 10) {
        $config = $this->db->getRabbitMQConfig($configId);
        if (!$config) {
            throw new Exception("Configuração não encontrada");
        }

        try {
            $connection = $this->getConnection($config);
            $channel = $connection->channel();

            // Declarar queue
            $channel->queueDeclare(
                $config['queue'],
                false,
                true,
                false,
                false
            );

            $messages = [];
            $processed = 0;
            $consumerTag = 'consumer_' . uniqid();

            // Callback para processar mensagens
            $processCallback = function($msg) use (&$messages, &$processed, $config, $limit) {
                $messageData = json_decode($msg->getBody(), true);
                $messageId = $msg->getConsumerTag() ?? uniqid('msg_', true);
                
                try {
                    if (isset($messageData['_index']) && isset($messageData['_id'])) {
                        $this->documentManager->indexDocument(
                            $messageData['_index'],
                            $messageData['_id'],
                            $messageData['data'] ?? $messageData
                        );
                        
                        $this->db->updateRabbitMQMessageStatus($messageId, 'processed');
                        
                        $messages[] = [
                            'status' => 'success',
                            'message' => 'Documento indexado com sucesso',
                            'data' => $messageData
                        ];
                    } else {
                        $messages[] = [
                            'status' => 'skipped',
                            'message' => 'Mensagem sem índice definido',
                            'data' => $messageData
                        ];
                    }
                    
                    $msg->ack();
                } catch (Exception $e) {
                    $this->db->updateRabbitMQMessageStatus($messageId, 'failed', $e->getMessage());
                    
                    $messages[] = [
                        'status' => 'error',
                        'message' => $e->getMessage(),
                        'data' => $messageData
                    ];
                    
                    $msg->nack(true);
                }
                
                $processed++;
                return $processed < $limit;
            };

            // Consumir mensagens
            $channel->basicConsume(
                $config['queue'],
                $consumerTag,
                $processCallback,
                false,
                false
            );

            // Aguardar processamento
            $timeout = 5;
            $start = time();
            while ($processed < $limit && (time() - $start) < $timeout) {
                usleep(100000); // 100ms
            }

            $channel->close();
            
            return [
                'status' => 'success',
                'message' => "Processadas {$processed} mensagens",
                'messages' => $messages
            ];
        } catch (Exception $e) {
            throw new Exception("Erro ao consumir mensagens: " . $e->getMessage());
        }
    }

    public function getMessages($configId = null, $status = null, $limit = 100) {
        return $this->db->getRabbitMQMessages($configId, $status, $limit);
    }

    public function getConfigs() {
        return $this->db->getAllRabbitMQConfigs();
    }

    public function saveConfig($data) {
        $required = ['name', 'host', 'port', 'username', 'password', 'queue'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Campo '{$field}' é obrigatório");
            }
        }

        if (isset($data['connection_type']) && !in_array($data['connection_type'], ['producer', 'consumer', 'both'])) {
            $data['connection_type'] = 'producer';
        }

        $result = $this->db->saveRabbitMQConfig($data);
        if (!$result) {
            throw new Exception("Erro ao salvar configuração");
        }

        return [
            'status' => 'success',
            'message' => 'Configuração salva com sucesso'
        ];
    }

    public function deleteConfig($id) {
        $config = $this->db->getRabbitMQConfig($id);
        if (!$config) {
            throw new Exception("Configuração não encontrada");
        }

        $this->db->deleteRabbitMQConfig($id);
        return [
            'status' => 'success',
            'message' => 'Configuração removida com sucesso'
        ];
    }

    public function testAndSave($data) {
        $result = $this->saveConfig($data);
        
        if ($result['status'] === 'success') {
            $configs = $this->getConfigs();
            $lastConfig = $configs[0] ?? null;
            if ($lastConfig) {
                try {
                    $this->testConnection($lastConfig['id']);
                } catch (Exception $e) {
                    return [
                        'status' => 'partial',
                        'message' => 'Configuração salva, mas falha na conexão: ' . $e->getMessage(),
                        'config' => $lastConfig
                    ];
                }
            }
        }
        
        return $result;
    }
}
