<?php

namespace RabbitMQ;

class Channel {
    private $connection;
    private $channelId;
    private $consumerTag;
    private $messageCallbacks = [];
    
    public function __construct($connection, $channelId) {
        $this->connection = $connection;
        $this->channelId = $channelId;
    }
    
    public function queueDeclare($queue, $passive = false, $durable = true, $exclusive = false, $autoDelete = false) {
        $this->connection->sendMethod($this->channelId, 50, 10, [
            'queue' => $queue,
            'passive' => $passive,
            'durable' => $durable,
            'exclusive' => $exclusive,
            'auto_delete' => $autoDelete,
            'nowait' => false
        ]);
        
        $result = $this->connection->readMethod($this->channelId, 50, 11);
        return $result;
    }
    
    public function exchangeDeclare($exchange, $type = 'topic', $passive = false, $durable = true, $autoDelete = false) {
        $this->connection->sendMethod($this->channelId, 40, 10, [
            'exchange' => $exchange,
            'type' => $type,
            'passive' => $passive,
            'durable' => $durable,
            'auto_delete' => $autoDelete,
            'nowait' => false
        ]);
        
        $this->connection->readMethod($this->channelId, 40, 11);
        return true;
    }
    
    public function queueBind($queue, $exchange, $routingKey = '') {
        $this->connection->sendMethod($this->channelId, 50, 20, [
            'queue' => $queue,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'nowait' => false
        ]);
        
        $this->connection->readMethod($this->channelId, 50, 21);
        return true;
    }
    
    public function basicPublish($exchange, $routingKey, $message, $properties = []) {
        // Basic.publish
        $this->connection->sendMethod($this->channelId, 60, 40, [
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'mandatory' => false,
            'immediate' => false
        ]);
        
        // Content header
        $this->sendContentHeader($message, $properties);
        
        // Body
        $this->sendBody($message);
        
        return true;
    }
    
    private function sendContentHeader($message, $properties) {
        $properties['delivery_mode'] = 2; // Persistent
        
        $payload = pack('nN', 0, 0); // class_id, weight
        $payload .= $this->encodeProperties($properties);
        $payload .= "\x00"; // end of properties
        
        $this->connection->sendFrame(AMQP::FRAME_HEADER, $this->channelId, $payload);
    }
    
    private function encodeProperties($properties) {
        $flags = 0;
        $result = '';
        
        // Content-type
        if (isset($properties['content_type'])) {
            $flags |= 0x8000;
            $result .= pack('n', strlen($properties['content_type'])) . $properties['content_type'];
        }
        
        // Content-encoding
        if (isset($properties['content_encoding'])) {
            $flags |= 0x4000;
            $result .= pack('n', strlen($properties['content_encoding'])) . $properties['content_encoding'];
        }
        
        // Headers
        if (isset($properties['headers'])) {
            $flags |= 0x2000;
            // Simples: ignorar headers por enquanto
        }
        
        // Delivery mode
        if (isset($properties['delivery_mode'])) {
            $flags |= 0x1000;
            $result .= pack('C', $properties['delivery_mode']);
        }
        
        // Priority
        if (isset($properties['priority'])) {
            $flags |= 0x0800;
            $result .= pack('C', $properties['priority']);
        }
        
        // Correlation id
        if (isset($properties['correlation_id'])) {
            $flags |= 0x0400;
            $result .= pack('n', strlen($properties['correlation_id'])) . $properties['correlation_id'];
        }
        
        // Reply to
        if (isset($properties['reply_to'])) {
            $flags |= 0x0200;
            $result .= pack('n', strlen($properties['reply_to'])) . $properties['reply_to'];
        }
        
        // Expiration
        if (isset($properties['expiration'])) {
            $flags |= 0x0100;
            $result .= pack('n', strlen($properties['expiration'])) . $properties['expiration'];
        }
        
        // Message id
        if (isset($properties['message_id'])) {
            $flags |= 0x0080;
            $result .= pack('n', strlen($properties['message_id'])) . $properties['message_id'];
        }
        
        // Timestamp
        if (isset($properties['timestamp'])) {
            $flags |= 0x0040;
            $result .= pack('N', $properties['timestamp']);
        }
        
        // Type
        if (isset($properties['type'])) {
            $flags |= 0x0020;
            $result .= pack('n', strlen($properties['type'])) . $properties['type'];
        }
        
        // User id
        if (isset($properties['user_id'])) {
            $flags |= 0x0010;
            $result .= pack('n', strlen($properties['user_id'])) . $properties['user_id'];
        }
        
        // App id
        if (isset($properties['app_id'])) {
            $flags |= 0x0008;
            $result .= pack('n', strlen($properties['app_id'])) . $properties['app_id'];
        }
        
        // Cluster id
        if (isset($properties['cluster_id'])) {
            $flags |= 0x0004;
            $result .= pack('n', strlen($properties['cluster_id'])) . $properties['cluster_id'];
        }
        
        return pack('n', $flags) . $result;
    }
    
    private function sendBody($body) {
        $this->connection->sendFrame(AMQP::FRAME_BODY, $this->channelId, $body);
    }
    
    public function basicConsume($queue, $consumerTag, $callback, $noAck = false, $exclusive = false) {
        $this->consumerTag = $consumerTag;
        $this->messageCallbacks[$consumerTag] = $callback;
        
        $this->connection->sendMethod($this->channelId, 60, 20, [
            'queue' => $queue,
            'consumer_tag' => $consumerTag,
            'no_local' => false,
            'no_ack' => $noAck,
            'exclusive' => $exclusive,
            'nowait' => false
        ]);
        
        $this->connection->readMethod($this->channelId, 60, 21);
        
        // Iniciar loop de consumo
        $this->consumeLoop();
    }
    
    private function consumeLoop() {
        while (true) {
            $frame = $this->connection->readFrame();
            
            if ($frame['type'] === AMQP::FRAME_METHOD) {
                $this->handleMethodFrame($frame);
            } elseif ($frame['type'] === AMQP::FRAME_HEADER) {
                // Headers serão processados junto com o body
            } elseif ($frame['type'] === AMQP::FRAME_BODY) {
                // Processar body
            }
        }
    }
    
    private function handleMethodFrame($frame) {
        $data = unpack('nclass/nmethod', substr($frame['payload'], 0, 4));
        $payload = substr($frame['payload'], 4);
        
        // Basic.deliver (60, 60)
        if ($data['class'] === 60 && $data['method'] === 60) {
            $args = $this->decodeDelivery($payload);
            
            // Ler header
            $header = $this->connection->readFrame();
            if ($header['type'] !== AMQP::FRAME_HEADER) {
                return;
            }
            
            // Ler body
            $bodyFrame = $this->connection->readFrame();
            if ($bodyFrame['type'] !== AMQP::FRAME_BODY) {
                return;
            }
            
            $body = $bodyFrame['payload'];
            
            // Chamar callback
            if (isset($this->messageCallbacks[$args['consumer_tag']])) {
                $callback = $this->messageCallbacks[$args['consumer_tag']];
                $message = new Message($body, $args, $this);
                $callback($message);
            }
        }
    }
    
    private function decodeDelivery($payload) {
        $result = [];
        $pos = 0;
        
        // consumer_tag (shortstr)
        $len = unpack('n', substr($payload, $pos, 2))[1];
        $pos += 2;
        $result['consumer_tag'] = substr($payload, $pos, $len);
        $pos += $len;
        
        // delivery_tag (long long)
        $result['delivery_tag'] = unpack('N', substr($payload, $pos, 4))[1];
        $pos += 4;
        
        // redelivered (bit)
        $result['redelivered'] = unpack('c', substr($payload, $pos, 1))[1] === 1;
        $pos += 1;
        
        // exchange (shortstr)
        $len = unpack('n', substr($payload, $pos, 2))[1];
        $pos += 2;
        $result['exchange'] = substr($payload, $pos, $len);
        $pos += $len;
        
        // routing_key (shortstr)
        $len = unpack('n', substr($payload, $pos, 2))[1];
        $pos += 2;
        $result['routing_key'] = substr($payload, $pos, $len);
        $pos += $len;
        
        return $result;
    }
    
    public function basicAck($deliveryTag) {
        $this->connection->sendMethod($this->channelId, 60, 80, [
            'delivery_tag' => $deliveryTag,
            'multiple' => false
        ]);
    }
    
    public function basicNack($deliveryTag, $requeue = true) {
        $this->connection->sendMethod($this->channelId, 60, 120, [
            'delivery_tag' => $deliveryTag,
            'multiple' => false,
            'requeue' => $requeue
        ]);
    }
    
    public function close() {
        $this->connection->sendMethod($this->channelId, 20, 40, []);
        $this->connection->readMethod($this->channelId, 20, 41);
    }
}
