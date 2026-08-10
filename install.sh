#!/bin/bash

echo "🔧 Instalando ElasticSearch Lite com RabbitMQ (PHP Puro)"

# Criar diretórios
mkdir -p data public/css public/js libs/RabbitMQ src workers api

# Dar permissões
chmod 755 data
chmod 755 workers/*.php 2>/dev/null || true
chmod +x workers/rabbitmq_consumer.php 2>/dev/null || true

# Verificar PHP
PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1-2)
echo "✅ PHP Versão: $PHP_VERSION"

# Verificar SQLite
if command -v sqlite3 &> /dev/null; then
    echo "✅ SQLite encontrado"
else
    echo "⚠️  SQLite não encontrado (necessário para o banco de dados)"
fi

# Verificar extensão PDO SQLite
if php -m | grep -q pdo_sqlite; then
    echo "✅ Extensão PDO SQLite habilitada"
else
    echo "❌ Extensão PDO SQLite não habilitada"
    echo "   Para habilitar:"
    echo "   - Ubuntu/Debian: sudo apt-get install php-sqlite3"
    echo "   - CentOS/RHEL: sudo yum install php-pdo_sqlite"
fi

# Verificar RabbitMQ (opcional)
if command -v rabbitmqctl &> /dev/null; then
    echo "✅ RabbitMQ encontrado"
    RABBITMQ_STATUS=$(rabbitmqctl status 2>/dev/null | grep -c "running")
    if [ "$RABBITMQ_STATUS" -gt 0 ]; then
        echo "✅ RabbitMQ está rodando"
    else
        echo "⚠️  RabbitMQ não está rodando"
        echo "   Iniciar: sudo systemctl start rabbitmq-server"
    fi
else
    echo "⚠️  RabbitMQ não encontrado (opcional)"
    echo "   Instale para usar mensageria:"
    echo "   - Ubuntu/Debian: sudo apt-get install rabbitmq-server"
    echo "   - CentOS/RHEL: sudo yum install rabbitmq-server"
fi

echo ""
echo "✅ Instalação concluída!"
echo ""
echo "📋 Próximos passos:"
echo "1. Configure o servidor web para apontar para este diretório"
echo "2. Acesse http://localhost para usar a interface web"
echo "3. Para usar RabbitMQ, inicie o worker:"
echo "   php workers/rabbitmq_consumer.php"
echo ""
echo "📚 Documentação da API disponível em /api/"
