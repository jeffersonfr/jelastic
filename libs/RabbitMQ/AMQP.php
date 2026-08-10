<?php

namespace RabbitMQ;

/**
 * Implementação pura do protocolo AMQP 0-9-1
 * Sem dependências externas
 */
class AMQP {
    const PROTOCOL_HEADER = "AMQP\x00\x00\x09\x01";
    const FRAME_METHOD = 1;
    const FRAME_HEADER = 2;
    const FRAME_BODY = 3;
    const FRAME_HEARTBEAT = 8;
    
    private $socket;
    private $channelMax = 0;
    private $frameMax = 131072;
    private $heartbeat = 0;
    private $channels = [];
    private $connection;
    
    public function connect($host, $port, $vhost, $username, $password) {
        // Conectar via TCP
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$this->socket) {
            throw new \Exception("Não foi possível conectar ao RabbitMQ: $errstr ($errno)");
        }
        
        stream_set_timeout($this->socket, 5);
        stream_set_blocking($this->socket, true);
        
        // Enviar protocol header
        fwrite($this->socket, self::PROTOCOL_HEADER);
        
        // Receber connection.start
        $frame = $this->readFrame();
        if ($frame['type'] !== self::FRAME_METHOD) {
            throw new \Exception("Erro no handshake: tipo de frame inesperado");
        }
        
        // Connection.start-ok
        $this->sendMethod(0, 10, 11, [
            'client_properties' => [
                'product' => 'PHP-Pure-RabbitMQ',
                'version' => '1.0',
                'platform' => 'PHP',
                'copyright' => 'MIT',
                'information' => 'Pure PHP AMQP Client'
            ],
            'mechanism' => 'PLAIN',
            'response' => $this->getPlainAuth($username, $password),
            'locale' => 'en_US'
        ]);
        
        // Receber connection.tune
        $tune = $this->readMethod(0, 10, 30);
        $this->channelMax = $tune['channel_max'] ?? 0;
        $this->frameMax = $tune['frame_max'] ?? 131072;
        $this->heartbeat = $tune['heartbeat'] ?? 0;
        
        // Connection.tune-ok
        $this->sendMethod(0, 10, 31, [
            'channel_max' => $this->channelMax,
            'frame_max' => $this->frameMax,
            'heartbeat' => $this->heartbeat
        ]);
        
        // Connection.open
        $this->sendMethod(0, 10, 40, ['virtual_host' => $vhost, 'capabilities' => '']);
        
        // Connection.open-ok
        $this->readMethod(0, 10, 41);
        
        return true;
    }
    
    private function getPlainAuth($username, $password) {
        return "\x00" . $username . "\x00" . $password;
    }
    
    public function channel($channelId = null) {
        if ($channelId === null) {
            $channelId = count($this->channels) + 1;
        }
        
        // Channel.open
        $this->sendMethod($channelId, 20, 10, []);
        $this->readMethod($channelId, 20, 11);
        
        $this->channels[$channelId] = true;
        return new Channel($this, $channelId);
    }
    
    public function sendMethod($channel, $class, $method, $args = []) {
        $payload = $this->encodeMethod($class, $method, $args);
        $this->sendFrame(self::FRAME_METHOD, $channel, $payload);
    }
    
    public function sendFrame($type, $channel, $payload = '') {
        $length = strlen($payload);
        $frame = pack('CnN', $type, $channel, $length) . $payload . "\xCE";
        fwrite($this->socket, $frame);
    }
    
    public function readFrame() {
        $header = fread($this->socket, 7);
        if (strlen($header) !== 7) {
            throw new \Exception("Falha ao ler frame header");
        }
        
        $data = unpack('Ctype/nchannel/Nlength', $header);
        $payload = '';
        
        if ($data['length'] > 0) {
            $payload = fread($this->socket, $data['length']);
            if (strlen($payload) !== $data['length']) {
                throw new \Exception("Falha ao ler payload do frame");
            }
        }
        
        $end = fread($this->socket, 1);
        if ($end !== "\xCE") {
            throw new \Exception("Frame end marker inválido");
        }
        
        return [
            'type' => $data['type'],
            'channel' => $data['channel'],
            'length' => $data['length'],
            'payload' => $payload
        ];
    }
    
    public function readMethod($channel, $class, $method) {
        $frame = $this->readFrame();
        if ($frame['type'] !== self::FRAME_METHOD) {
            throw new \Exception("Esperado frame de método");
        }
        
        // Decodificar método
        $data = unpack('nclass/nmethod', substr($frame['payload'], 0, 4));
        
        if ($data['class'] !== $class || $data['method'] !== $method) {
            throw new \Exception("Método inesperado: class={$data['class']}, method={$data['method']}");
        }
        
        // Decodificar argumentos
        $args = $this->decodeTable(substr($frame['payload'], 4));
        return $args;
    }
    
    private function encodeMethod($class, $method, $args) {
        $encoded = pack('nn', $class, $method);
        $encoded .= $this->encodeTable($args);
        return $encoded;
    }
    
    private function encodeTable($table) {
        $result = '';
        foreach ($table as $key => $value) {
            $result .= pack('n', strlen($key)) . $key;
            $result .= $this->encodeValue($value);
        }
        return $result;
    }
    
    private function encodeValue($value) {
        if (is_string($value)) {
            return "\x53" . pack('N', strlen($value)) . $value;
        } elseif (is_int($value)) {
            return "\x49" . pack('N', $value);
        } elseif (is_bool($value)) {
            return "\x74" . ($value ? "\x01" : "\x00");
        } elseif (is_null($value)) {
            return "\x56";
        } elseif (is_array($value)) {
            // Assumindo array associativo (table)
            $encoded = '';
            foreach ($value as $k => $v) {
                $encoded .= pack('n', strlen($k)) . $k . $this->encodeValue($v);
            }
            return "\x46" . pack('N', strlen($encoded)) . $encoded;
        }
        return "\x56";
    }
    
    private function decodeTable($data) {
        $result = [];
        $pos = 0;
        $len = strlen($data);
        
        while ($pos < $len) {
            if ($pos + 2 > $len) break;
            $keyLen = unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
            
            if ($pos + $keyLen > $len) break;
            $key = substr($data, $pos, $keyLen);
            $pos += $keyLen;
            
            if ($pos >= $len) break;
            $type = substr($data, $pos, 1);
            $pos += 1;
            
            $value = $this->decodeValue($data, $pos, $type);
            $result[$key] = $value['value'];
            $pos = $value['pos'];
        }
        
        return $result;
    }
    
    private function decodeValue($data, $pos, $type) {
        switch ($type) {
            case 'S': // String
                $len = unpack('N', substr($data, $pos, 4))[1];
                $pos += 4;
                $value = substr($data, $pos, $len);
                $pos += $len;
                return ['value' => $value, 'pos' => $pos];
                
            case 'I': // Int
                $value = unpack('N', substr($data, $pos, 4))[1];
                $pos += 4;
                return ['value' => $value, 'pos' => $pos];
                
            case 't': // Boolean
                $value = unpack('c', substr($data, $pos, 1))[1] === 1;
                $pos += 1;
                return ['value' => $value, 'pos' => $pos];
                
            case 'V': // Null
                return ['value' => null, 'pos' => $pos];
                
            case 'F': // Table
                $len = unpack('N', substr($data, $pos, 4))[1];
                $pos += 4;
                $tableData = substr($data, $pos, $len);
                $pos += $len;
                $value = $this->decodeTable($tableData);
                return ['value' => $value, 'pos' => $pos];
                
            default:
                return ['value' => null, 'pos' => $pos];
        }
    }
    
    public function close() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}
