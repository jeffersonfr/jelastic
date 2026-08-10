## Install dependencies

    $ sudo apt install php-sqlite3
    $ ./install.sh
    $ ./config-all.sh

## Testando interface

    - iniciar worker para consumir mensagens rabbitmq

        $ php workers/rabbitmq_consumer.php

    - iniciar o servidor local

        $ php -S localhost -t .

## Testando API

    - criando indice

        $ curl -X POST http://localhost/api/indexes \
          -H "Content-Type: application/json" \
          -d '{"name":"produtos","fields":["nome","descricao","categoria"]}'

    - indexar documentos

        $ curl -X POST http://localhost/api/indexes/produtos/documents \
          -H "Content-Type: application/json" \
          -d '{"id":"1","data":{"nome":"iPhone","descricao":"Smartphone Apple","categoria":"Eletrônicos"}}'

    - buscar

        $ curl "http://localhost/api/search/produtos?q=iphone"

