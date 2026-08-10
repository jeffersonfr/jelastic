#!/usr/bin/env php
<?php

/**
 * Script de teste para RabbitMQ
 * Uso: php test_rabbitmq.php
 */

// Autoload manual
spl_autoload_register(function($class) {
    $paths = [
        __DIR__ . '/src/' . $class . '.php',
        __DIR__ . '/libs/RabbitMQ/' . $class . '.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return true;
        }
    }
    return false;
});

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/RabbitMQManager.php';

echo "🧪 Testando RabbitMQ Manager (PHP Puro)\n\n";

try {
    $db = Database::getInstance();
    $db->createTables();
    $manager = new RabbitMQManager();
    
    echo "✅ RabbitMQ Manager instanciado\n";
    
    // Listar configurações
    $configs = $manager->getConfigs();
    echo "📋 Configurações encontradas: " . count($configs) . "\n";
    
    if (empty($configs)) {
        echo "\n⚠️  Nenhuma configuração encontrada.\n";
        echo "Crie uma configuração pela interface web.\n";
    } else {
        foreach ($configs as $config) {
            echo "\n📦 Configuração: {$config['name']}\n";
            echo "   Host: {$config['host']}:{$config['port']}\n";
            echo "   Queue: {$config['queue']}\n";
            echo "   Status: {$config['status']}\n";
            
            // Testar conexão
            try {
                $test = $manager->testConnection($config['id']);
                echo "   ✅ Conexão OK\n";
            } catch (Exception $e) {
                echo "   ❌ Erro: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✅ Teste concluído!\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
