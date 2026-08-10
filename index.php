<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ElasticSearch Lite - Sistema de Busca</title>
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>🔍 ElasticSearch Lite</h1>
            <p>Sistema de busca com SQLite + FTS5</p>
        </header>

        <nav class="tabs">
            <button class="tab-btn active" data-tab="indexes">Índices</button>
            <button class="tab-btn" data-tab="search">Buscar</button>
            <button class="tab-btn" data-tab="documents">Documentos</button>
        </nav>

        <!-- Aba: Índices -->
        <div id="tab-indexes" class="tab-content active">
            <div class="card">
                <h2>Gerenciar Índices</h2>
                <form id="create-index-form" class="form-inline">
                    <div class="form-group">
                        <input type="text" id="index-name" placeholder="Nome do índice" required>
                    </div>
                    <div class="form-group">
                        <input type="text" id="index-fields" placeholder="Campos (separados por vírgula)" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Criar Índice</button>
                </form>
            </div>

            <div class="card">
                <h3>Índices Existentes</h3>
                <div id="indexes-list"></div>
            </div>
        </div>

        <!-- Aba: Busca -->
        <div id="tab-search" class="tab-content">
            <div class="card">
                <h2>Buscar Documentos</h2>
                <form id="search-form" class="form-inline">
                    <div class="form-group">
                        <select id="search-index" required>
                            <option value="">Selecione um índice</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" id="search-query" placeholder="Termos de busca..." required>
                    </div>
                    <div class="form-group">
                        <input type="number" id="search-limit" value="20" placeholder="Limite">
                    </div>
                    <button type="submit" class="btn btn-primary">Buscar</button>
                </form>
            </div>

            <div id="search-results"></div>
        </div>

        <!-- Aba: Documentos -->
        <div id="tab-documents" class="tab-content">
            <div class="card">
                <h2>Gerenciar Documentos</h2>
                <form id="index-document-form">
                    <div class="form-group">
                        <select id="doc-index" required>
                            <option value="">Selecione um índice</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" id="doc-id" placeholder="ID do documento" required>
                    </div>
                    <div class="form-group">
                        <textarea id="doc-data" placeholder='Dados em JSON {"campo": "valor"}' rows="5" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Indexar Documento</button>
                </form>
            </div>

            <div class="card">
                <h3>Documentos do Índice</h3>
                <div class="form-group">
                    <select id="view-doc-index">
                        <option value="">Selecione um índice</option>
                    </select>
                    <button onclick="loadDocuments()" class="btn btn-secondary">Carregar</button>
                </div>
                <div id="documents-list"></div>
            </div>
        </div>
    </div>

    <script src="/public/js/app.js"></script>
</body>
</html>
