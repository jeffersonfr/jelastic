<?php

namespace RabbitMQ;

class Message {
    private $body;
    private $properties;
    private $channel;
    private $deliveryTag;
    private $consumerTag;
    
    public function __construct($body, $properties, $channel) {
        $this->body = $body;
        $this->properties = $properties;
        $this->channel = $channel;
        $this->deliveryTag = $properties['delivery_tag'] ?? null;
        $this->consumerTag = $properties['consumer_tag'] ?? null;
    }
    
    public function getBody() {
        return $this->body;
    }
    
    public function getDeliveryTag() {
        return $this->deliveryTag;
    }
    
    public function getConsumerTag() {
        return $this->consumerTag;
    }
    
    public function ack() {
        if ($this->channel && $this->deliveryTag) {
            $this->channel->basicAck($this->deliveryTag);
        }
    }
    
    public function nack($requeue = true) {
        if ($this->channel && $this->deliveryTag) {
            $this->channel->basicNack($this->deliveryTag, $requeue);
        }
    }
}
