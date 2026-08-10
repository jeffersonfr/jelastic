// Estado da aplicação
const state = {
    indexes: [],
    currentTab: 'indexes'
};

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    loadIndexes();
    loadIndexSelects();
    setupEventListeners();
});

// Configurar eventos
function setupEventListeners() {
    // Tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tab = this.dataset.tab;
            switchTab(tab);
        });
    });

    // Criar índice
    document.getElementById('create-index-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const name = document.getElementById('index-name').value.trim();
        const fields = document.getElementById('index-fields').value.split(',').map(f => f.trim()).filter(f => f);
        
        if (!name || fields.length === 0) {
            showMessage('Preencha todos os campos', 'error');
            return;
        }

        try {
            const response = await fetch('/api/indexes', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({name, fields})
            });
            
            const result = await response.json();
            if (result.status === 'success') {
                showMessage(result.message, 'success');
                document.getElementById('index-name').value = '';
                document.getElementById('index-fields').value = '';
                loadIndexes();
                loadIndexSelects();
            } else {
                showMessage(result.error || 'Erro ao criar índice', 'error');
            }
        } catch (error) {
            showMessage('Erro de comunicação com o servidor', 'error');
        }
    });

    // Buscar
    document.getElementById('search-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const index = document.getElementById('search-index').value;
        const query = document.getElementById('search-query').value.trim();
        const limit = document.getElementById('search-limit').value || 20;
        
        if (!index || !query) {
            showMessage('Selecione um índice e digite uma busca', 'error');
            return;
        }

        await performSearch(index, query, limit);
    });

    // Indexar documento
    document.getElementById('index-document-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const index = document.getElementById('doc-index').value;
        const id = document.getElementById('doc-id').value.trim();
        const dataText = document.getElementById('doc-data').value.trim();
        
        if (!index || !id || !dataText) {
            showMessage('Preencha todos os campos', 'error');
            return;
        }

        try {
            const data = JSON.parse(dataText);
            const response = await fetch(`/api/indexes/${index}/documents`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id, data})
            });
            
            const result = await response.json();
            if (result.status === 'success') {
                showMessage(result.message, 'success');
                document.getElementById('doc-id').value = '';
                document.getElementById('doc-data').value = '';
                loadDocuments();
            } else {
                showMessage(result.error || 'Erro ao indexar documento', 'error');
            }
        } catch (error) {
            if (error instanceof SyntaxError) {
                showMessage('JSON inválido. Verifique o formato dos dados.', 'error');
            } else {
                showMessage('Erro ao processar requisição', 'error');
            }
        }
    });
}

// Funções de UI
function switchTab(tab) {
    state.currentTab = tab;
    
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById(`tab-${tab}`).classList.add('active');
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    
    if (tab === 'documents') {
        loadDocuments();
    }
}

function showMessage(text, type = 'info') {
    const container = document.querySelector('.container');
    const existing = document.querySelector('.message-container');
    if (existing) existing.remove();
    
    const div = document.createElement('div');
    div.className = `message-container message-${type}`;
    div.innerHTML = text;
    container.prepend(div);
    
    setTimeout(() => div.remove(), 5000);
}

// Carregar índices
async function loadIndexes() {
    try {
        const response = await fetch('/api/indexes');
        const data = await response.json();
        state.indexes = data;
        renderIndexes(data);
    } catch (error) {
        console.error('Erro ao carregar índices:', error);
    }
}

function renderIndexes(indexes) {
    const container = document.getElementById('indexes-list');
    
    if (indexes.length === 0) {
        container.innerHTML = '<p class="empty">Nenhum índice criado ainda.</p>';
        return;
    }
    
    let html = '<div class="index-grid">';
    indexes.forEach(index => {
        html += `
            <div class="index-card">
                <h4>📊 ${index.name}</h4>
                <div class="index-info">
                    <span>📄 ${index.document_count || 0} documentos</span>
                    <span>📅 ${new Date(index.created_at).toLocaleDateString('pt-BR')}</span>
                </div>
                <div class="index-fields">
                    <small>Campos: ${Array.isArray(index.fields) ? index.fields.join(', ') : index.fields}</small>
                </div>
                <button onclick="deleteIndex('${index.name}')" class="btn btn-danger btn-sm">Excluir</button>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

async function deleteIndex(name) {
    if (!confirm(`Tem certeza que deseja excluir o índice "${name}"?`)) return;
    
    try {
        const response = await fetch(`/api/indexes/${name}`, {
            method: 'DELETE'
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            showMessage(result.message, 'success');
            loadIndexes();
            loadIndexSelects();
        } else {
            showMessage(result.error || 'Erro ao excluir índice', 'error');
        }
    } catch (error) {
        showMessage('Erro de comunicação com o servidor', 'error');
    }
}

// Carregar selects de índices
async function loadIndexSelects() {
    try {
        const response = await fetch('/api/indexes');
        const data = await response.json();
        
        const selects = ['search-index', 'doc-index', 'view-doc-index'];
        selects.forEach(id => {
            const select = document.getElementById(id);
            if (!select) return;
            
            const currentValue = select.value;
            select.innerHTML = '<option value="">Selecione um índice</option>';
            
            data.forEach(index => {
                const option = document.createElement('option');
                option.value = index.name;
                option.textContent = `${index.name} (${index.document_count || 0} docs)`;
                if (option.value === currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    } catch (error) {
        console.error('Erro ao carregar selects:', error);
    }
}

// Buscar
async function performSearch(index, query, limit) {
    const container = document.getElementById('search-results');
    container.innerHTML = '<div class="loading">Buscando...</div>';
    
    try {
        const url = `/api/search/${index}?q=${encodeURIComponent(query)}&limit=${limit}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.status === 'success') {
            renderSearchResults(data);
        } else {
            container.innerHTML = `<div class="error">${data.error || 'Erro na busca'}</div>`;
        }
    } catch (error) {
        container.innerHTML = '<div class="error">Erro de comunicação com o servidor</div>';
    }
}

function renderSearchResults(data) {
    const container = document.getElementById('search-results');
    
    if (data.total === 0) {
        container.innerHTML = '<p class="empty">Nenhum resultado encontrado.</p>';
        return;
    }
    
    let html = `
        <div class="search-stats">
            <span>${data.total} resultados encontrados (${data.limit} por página)</span>
            <span>Query: "${data.query}"</span>
        </div>
    `;
    
    data.hits.forEach((hit, index) => {
        html += `
            <div class="result-item">
                <div class="result-header">
                    <span class="result-number">${index + 1}</span>
                    <span class="result-score">Score: ${hit.score.toFixed(4)}</span>
                </div>
                <div class="result-data">
                    <pre>${JSON.stringify(hit.data, null, 2)}</pre>
                </div>
                <div class="result-meta">
                    <span>ID: ${hit.id}</span>
                    <span>Atualizado: ${new Date(hit.updated_at).toLocaleString('pt-BR')}</span>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Carregar documentos
async function loadDocuments() {
    const select = document.getElementById('view-doc-index');
    const indexName = select.value;
    const container = document.getElementById('documents-list');
    
    if (!indexName) {
        container.innerHTML = '<p class="empty">Selecione um índice para ver os documentos.</p>';
        return;
    }
    
    container.innerHTML = '<div class="loading">Carregando documentos...</div>';
    
    try {
        // Buscar documentos através da API de busca com consulta vazia
        const response = await fetch(`/api/search/${indexName}?q=*&limit=50`);
        const data = await response.json();
        
        if (data.status === 'success') {
            renderDocumentsList(data.hits);
        } else {
            container.innerHTML = `<div class="error">${data.error || 'Erro ao carregar documentos'}</div>`;
        }
    } catch (error) {
        container.innerHTML = '<div class="error">Erro de comunicação com o servidor</div>';
    }
}

function renderDocumentsList(hits) {
    const container = document.getElementById('documents-list');
    
    if (hits.length === 0) {
        container.innerHTML = '<p class="empty">Nenhum documento neste índice.</p>';
        return;
    }
    
    let html = '<div class="documents-grid">';
    hits.forEach(hit => {
        html += `
            <div class="document-card">
                <div class="doc-header">
                    <strong>${hit.id}</strong>
                    <button onclick="deleteDocument('${hit.id}')" class="btn btn-danger btn-sm">✕</button>
                </div>
                <div class="doc-data">
                    <pre>${JSON.stringify(hit.data, null, 2)}</pre>
                </div>
                <div class="doc-meta">
                    <small>Atualizado: ${new Date(hit.updated_at).toLocaleString('pt-BR')}</small>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

async function deleteDocument(id) {
    if (!confirm(`Tem certeza que deseja excluir o documento "${id}"?`)) return;
    
    const index = document.getElementById('view-doc-index').value;
    if (!index) return;
    
    try {
        const response = await fetch(`/api/indexes/${index}/documents/${id}`, {
            method: 'DELETE'
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            showMessage(result.message, 'success');
            loadDocuments();
            loadIndexSelects();
        } else {
            showMessage(result.error || 'Erro ao excluir documento', 'error');
        }
    } catch (error) {
        showMessage('Erro de comunicação com o servidor', 'error');
    }
}

// ============================
// Módulo RabbitMQ
// ============================

async function loadRabbitMQConfigs() {
    try {
        const response = await fetch('/api/rabbitmq/configs');
        const data = await response.json();
        renderRabbitMQConfigs(data);
    } catch (error) {
        console.error('Erro ao carregar configurações RabbitMQ:', error);
    }
}

function renderRabbitMQConfigs(configs) {
    const container = document.getElementById('rabbitmq-configs');
    if (!container) return;

    if (configs.length === 0) {
        container.innerHTML = '<p class="empty">Nenhuma configuração RabbitMQ criada.</p>';
        return;
    }

    let html = '<div class="rabbitmq-grid">';
    configs.forEach(config => {
        const statusClass = config.status === 'active' ? 'status-active' : 'status-inactive';
        const typeLabel = config.connection_type === 'producer' ? '📤 Produtor' :
                         config.connection_type === 'consumer' ? '📥 Consumidor' : '🔄 Ambos';

        html += `
            <div class="rabbitmq-card">
                <div class="card-header">
                    <h4>🐰 ${config.name}</h4>
                    <span class="status-badge ${statusClass}">${config.status}</span>
                </div>
                <div class="card-body">
                    <div class="connection-info">
                        <span>${typeLabel}</span>
                        <span>${config.host}:${config.port}</span>
                        <span>Queue: ${config.queue}</span>
                    </div>
                    <div class="connection-details">
                        <small>VHost: ${config.vhost}</small>
                        ${config.exchange ? `<small>Exchange: ${config.exchange}</small>` : ''}
                        ${config.routing_key ? `<small>Routing Key: ${config.routing_key}</small>` : ''}
                    </div>
                    ${config.last_connected ? `<small>Última conexão: ${new Date(config.last_connected).toLocaleString('pt-BR')}</small>` : ''}
                    ${config.error_message ? `<div class="error-message">⚠️ ${config.error_message}</div>` : ''}
                </div>
                <div class="card-actions">
                    <button onclick="testRabbitMQConnection(${config.id})" class="btn btn-secondary btn-sm">Testar</button>
                    <button onclick="publishRabbitMQMessage(${config.id})" class="btn btn-primary btn-sm">Publicar</button>
                    <button onclick="consumeRabbitMQMessages(${config.id})" class="btn btn-success btn-sm">Consumir</button>
                    <button onclick="deleteRabbitMQConfig(${config.id})" class="btn btn-danger btn-sm">Excluir</button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

async function testRabbitMQConnection(configId) {
    try {
        const response = await fetch(`/api/rabbitmq/configs/${configId}/test`, {
            method: 'POST'
        });
        const result = await response.json();

        if (result.status === 'success') {
            showMessage('✅ ' + result.message, 'success');
        } else {
            showMessage('❌ ' + (result.error || 'Erro no teste'), 'error');
        }

        loadRabbitMQConfigs();
    } catch (error) {
        showMessage('Erro ao testar conexão', 'error');
    }
}

async function deleteRabbitMQConfig(configId) {
    if (!confirm('Tem certeza que deseja excluir esta configuração?')) return;

    try {
        const response = await fetch(`/api/rabbitmq/configs/${configId}`, {
            method: 'DELETE'
        });
        const result = await response.json();

        if (result.status === 'success') {
            showMessage(result.message, 'success');
            loadRabbitMQConfigs();
        } else {
            showMessage(result.error || 'Erro ao excluir', 'error');
        }
    } catch (error) {
        showMessage('Erro ao excluir configuração', 'error');
    }
}

async function publishRabbitMQMessage(configId) {
    const message = prompt('Digite a mensagem em formato JSON (inclua _index e _id):');
    if (!message) return;

    try {
        const messageData = JSON.parse(message);

        const response = await fetch(`/api/rabbitmq/configs/${configId}/publish`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: messageData})
        });

        const result = await response.json();
        if (result.status === 'success') {
            showMessage('✅ ' + result.message + ' (ID: ' + result.message_id + ')', 'success');
        } else {
            showMessage('❌ ' + (result.error || 'Erro ao publicar'), 'error');
        }
    } catch (error) {
        if (error instanceof SyntaxError) {
            showMessage('JSON inválido. Verifique o formato.', 'error');
        } else {
            showMessage('Erro ao publicar mensagem', 'error');
        }
    }
}

async function consumeRabbitMQMessages(configId) {
    const limit = prompt('Quantas mensagens consumir? (padrão: 10)', '10');
    if (limit === null) return;

    try {
        const response = await fetch(`/api/rabbitmq/configs/${configId}/consume`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({limit: parseInt(limit) || 10})
        });

        const result = await response.json();
        if (result.status === 'success') {
            showMessage('✅ ' + result.message, 'success');

            // Mostrar detalhes das mensagens processadas
            if (result.messages && result.messages.length > 0) {
                console.log('Mensagens processadas:', result.messages);
                alert('Processadas ' + result.messages.length + ' mensagens. Verifique o console para detalhes.');
            }
        } else {
            showMessage('❌ ' + (result.error || 'Erro ao consumir'), 'error');
        }
    } catch (error) {
        showMessage('Erro ao consumir mensagens', 'error');
    }
}

// Função para salvar configuração RabbitMQ
async function saveRabbitMQConfig() {
    const form = document.getElementById('rabbitmq-form');
    const formData = new FormData(form);

    const data = {
        name: document.getElementById('rmq-name').value,
        host: document.getElementById('rmq-host').value,
        port: parseInt(document.getElementById('rmq-port').value) || 5672,
        vhost: document.getElementById('rmq-vhost').value || '/',
        username: document.getElementById('rmq-username').value,
        password: document.getElementById('rmq-password').value,
        queue: document.getElementById('rmq-queue').value,
        exchange: document.getElementById('rmq-exchange').value || null,
        routing_key: document.getElementById('rmq-routing-key').value || null,
        connection_type: document.getElementById('rmq-type').value,
        status: document.getElementById('rmq-status').value
    };

    try {
        const response = await fetch('/api/rabbitmq/configs', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.status === 'success' || result.status === 'partial') {
            showMessage('✅ ' + result.message, 'success');
            form.reset();
            loadRabbitMQConfigs();
        } else {
            showMessage('❌ ' + (result.error || 'Erro ao salvar'), 'error');
        }
    } catch (error) {
        showMessage('Erro ao salvar configuração', 'error');
    }
}

// Adicionar nova tab no HTML
function addRabbitMQTab() {
    const tabs = document.querySelector('.tabs');
    const tabBtn = document.createElement('button');
    tabBtn.className = 'tab-btn';
    tabBtn.dataset.tab = 'rabbitmq';
    tabBtn.textContent = '🐰 RabbitMQ';
    tabs.appendChild(tabBtn);

    // Criar conteúdo da tab
    const container = document.querySelector('.container');
    const tabContent = document.createElement('div');
    tabContent.id = 'tab-rabbitmq';
    tabContent.className = 'tab-content';
    tabContent.innerHTML = `
        <div class="card">
            <h2>Configuração RabbitMQ</h2>
            <form id="rabbitmq-form" class="form-grid">
                <div class="form-group">
                    <label>Nome da Configuração</label>
                    <input type="text" id="rmq-name" required placeholder="Ex: Produtos Queue">
                </div>
                <div class="form-group">
                    <label>Host</label>
                    <input type="text" id="rmq-host" required placeholder="localhost">
                </div>
                <div class="form-group">
                    <label>Porta</label>
                    <input type="number" id="rmq-port" value="5672">
                </div>
                <div class="form-group">
                    <label>Virtual Host</label>
                    <input type="text" id="rmq-vhost" value="/">
                </div>
                <div class="form-group">
                    <label>Usuário</label>
                    <input type="text" id="rmq-username" required placeholder="guest">
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" id="rmq-password" required placeholder="guest">
                </div>
                <div class="form-group">
                    <label>Queue</label>
                    <input type="text" id="rmq-queue" required placeholder="minha_fila">
                </div>
                <div class="form-group">
                    <label>Exchange (opcional)</label>
                    <input type="text" id="rmq-exchange" placeholder="amq.topic">
                </div>
                <div class="form-group">
                    <label>Routing Key (opcional)</label>
                    <input type="text" id="rmq-routing-key" placeholder="chave.rota">
                </div>
                <div class="form-group">
                    <label>Tipo de Conexão</label>
                    <select id="rmq-type">
                        <option value="producer">Produtor (publicar)</option>
                        <option value="consumer">Consumidor (receber)</option>
                        <option value="both">Ambos</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="rmq-status">
                        <option value="active">Ativo</option>
                        <option value="inactive">Inativo</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <button type="button" onclick="saveRabbitMQConfig()" class="btn btn-primary">
                        💾 Salvar Configuração
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Configurações Existentes</h3>
            <div id="rabbitmq-configs"></div>
        </div>

        <div class="card">
            <h3>Mensagens Processadas</h3>
            <div class="form-group">
                <button onclick="loadRabbitMQMessages()" class="btn btn-secondary">Carregar Mensagens</button>
                <select id="rmq-message-filter" style="width: 200px;">
                    <option value="">Todos os status</option>
                    <option value="pending">Pendentes</option>
                    <option value="processed">Processados</option>
                    <option value="failed">Falhas</option>
                </select>
            </div>
            <div id="rabbitmq-messages"></div>
        </div>
    `;

    container.appendChild(tabContent);

    // Atualizar evento de tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tab = this.dataset.tab;
            switchTab(tab);
        });
    });
}

// Carregar mensagens do RabbitMQ
async function loadRabbitMQMessages() {
    const container = document.getElementById('rabbitmq-messages');
    if (!container) return;

    const statusFilter = document.getElementById('rmq-message-filter')?.value || '';
    const url = `/api/rabbitmq/messages?limit=50${statusFilter ? '&status=' + statusFilter : ''}`;

    container.innerHTML = '<div class="loading">Carregando mensagens...</div>';

    try {
        const response = await fetch(url);
        const messages = await response.json();

        if (messages.length === 0) {
            container.innerHTML = '<p class="empty">Nenhuma mensagem encontrada.</p>';
            return;
        }

        let html = '<div class="messages-table">';
        html += `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Exchange</th>
                        <th>Routing Key</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
        `;

        messages.forEach(msg => {
            const statusClass = msg.status === 'processed' ? 'status-success' :
                              msg.status === 'failed' ? 'status-error' : 'status-pending';

            html += `
                <tr>
                    <td><small>${msg.message_id}</small></td>
                    <td>${msg.exchange || '-'}</td>
                    <td>${msg.routing_key || '-'}</td>
                    <td><span class="status-badge ${statusClass}">${msg.status}</span></td>
                    <td><small>${new Date(msg.created_at).toLocaleString('pt-BR')}</small></td>
                    <td>
                        <button onclick="viewRabbitMQMessage(${msg.id})" class="btn btn-sm btn-secondary">Ver</button>
                    </td>
                </tr>
            `;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;

    } catch (error) {
        container.innerHTML = '<div class="error">Erro ao carregar mensagens</div>';
        console.error(error);
    }
}

// Ver mensagem específica
async function viewRabbitMQMessage(id) {
    try {
        const response = await fetch(`/api/rabbitmq/messages/${id}`);
        const msg = await response.json();

        alert(`Mensagem:\n${JSON.stringify(JSON.parse(msg.data), null, 2)}`);
    } catch (error) {
        showMessage('Erro ao carregar mensagem', 'error');
    }
}

// Inicializar módulo RabbitMQ quando carregar
document.addEventListener('DOMContentLoaded', function() {
    // Adicionar tab do RabbitMQ
    addRabbitMQTab();

    // Carregar configurações
    loadRabbitMQConfigs();

    // Setup do switch de tabs existente...
});

// Atualizar função switchTab para incluir rabbitmq
const originalSwitchTab = switchTab;
switchTab = function(tab) {
    originalSwitchTab(tab);
    if (tab === 'rabbitmq') {
        loadRabbitMQConfigs();
        loadRabbitMQMessages();
    }
};
