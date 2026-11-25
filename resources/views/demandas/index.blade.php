
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard de Demandas</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
        }

        .card {
            border: none;
            border-radius: 12px;
        }

        .card-indicador {
            border-left: 4px solid #0d6efd;
        }

        .card-indicador h2 {
            font-weight: 700;
        }

        .table td {
            vertical-align: middle;
        }
    </style>
</head>

<body>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1">Gerenciamento de Demandas</h3>
            <span class="text-muted">Laravel 8 + RabbitMQ</span>
        </div>

        <span class="badge bg-success" id="conexao">
            Atualização automática
        </span>
    </div>

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="row g-3 mb-4">

        <div class="col-md-3 col-6">
            <div class="card card-indicador shadow-sm p-3">
                <span class="text-muted">Total de Demandas</span>
                <h2 id="total">0</h2>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="card card-indicador shadow-sm p-3">
                <span class="text-muted">Pendentes</span>
                <h2 id="pendentes">0</h2>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="card card-indicador shadow-sm p-3">
                <span class="text-muted">Processando</span>
                <h2 id="processando">0</h2>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="card card-indicador shadow-sm p-3">
                <span class="text-muted">Finalizadas</span>
                <h2 id="finalizadas">0</h2>
            </div>
        </div>

    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">

            <div>
                <h5 class="fw-bold mb-1">Monitoramento RabbitMQ</h5>

                <button type="button" id="btn-reprocessar" class="btn btn-sm btn-outline-danger mt-2" onclick="reprocessarDlq()">
                    Reprocessar próxima mensagem da DLQ
                </button>

                <div id="resultado-dlq" class="small mt-2"></div>
            </div>

            <div class="row g-3">

                <div class="col-md-4">
                    <div class="card border p-3">
                        <span class="text-muted">Fila principal</span>
                        <h2 id="fila-processar">-</h2>
                        <small class="text-muted">demandas.processar</small>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card border p-3">
                        <span class="text-muted">Aguardando retry</span>
                        <h2 id="fila-retry">-</h2>
                        <small class="text-muted">demandas.retry</small>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card border p-3">
                        <span class="text-muted">Fila de erros</span>
                        <h2 id="fila-erro" class="text-danger">-</h2>
                        <small class="text-muted">demandas.erro</small>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">

            <h5 class="fw-bold mb-3">Cadastrar demanda</h5>

            <form action="{{ route('demandas.store') }}" method="POST">
                @csrf

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">Título</label>
                        <input type="text" name="titulo"
                               class="form-control"
                               maxlength="255"
                               value="{{ old('titulo') }}" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao"
                               class="form-control"
                               value="{{ old('descricao') }}">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">
                            Cadastrar
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Demandas cadastradas</h5>
                <small class="text-muted" id="ultima-atualizacao"></small>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Título</th>
                            <th>Descrição</th>
                            <th>Status</th>
                            <th>Cadastro</th>
                            <th>Ações</th>
                        </tr>
                    </thead>

                    <tbody id="tabela-demandas">
                        <tr>
                            <td colspan="6" class="text-center">
                                Carregando demandas...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<script>
    let atualizando = false;
    const demandasEnviando = new Set();

    function criarCelula(linha, valor) {
        const celula = document.createElement('td');
        celula.textContent = valor ?? '';
        linha.appendChild(celula);

        return celula;
    }

    function atualizarDemandas() {
        
        if (atualizando) {
            return;
        }

        atualizando = true;

        fetch("{{ route('demandas.dados') }}", {
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro ao consultar demandas');
            }

            return response.json();
        })
        .then(dados => {
            document.getElementById('total').textContent = dados.total;
            document.getElementById('pendentes').textContent = dados.pendentes;
            document.getElementById('processando').textContent = dados.processando;
            document.getElementById('finalizadas').textContent = dados.finalizadas;

            const tabela = document.getElementById('tabela-demandas');
            tabela.replaceChildren();

            dados.demandas.forEach(demanda => {
                const linha = document.createElement('tr');

                criarCelula(linha, demanda.id);
                criarCelula(linha, demanda.titulo);
                criarCelula(linha, demanda.descricao);

                const colunaStatus = document.createElement('td');
                const status = document.createElement('span');

                const valorStatus = String(demanda.status || '').trim().toLowerCase();

                status.className = 'badge';

                if (valorStatus === 'finalizada') {
                    status.classList.add('bg-success');
                } else if (['enfileirada', 'processando', 'em andamento'].includes(valorStatus)) {
                    status.classList.add('bg-primary');
                } else if (['pendente', 'aberta'].includes(valorStatus)) {
                    status.classList.add('bg-warning', 'text-dark');
                } else if (valorStatus === 'erro' || valorStatus === 'verificar_envio') {
                    status.classList.add('bg-danger');
                } else {
                    status.classList.add('bg-secondary');
                }

                status.textContent = demanda.status;
                colunaStatus.appendChild(status);
                linha.appendChild(colunaStatus);

                let cadastro = '';

                if (demanda.created_at) {
                    const data = new Date(demanda.created_at);

                    if (!isNaN(data.getTime())) {
                        cadastro = data.toLocaleString('pt-BR', {
                            timeZone: 'America/Sao_Paulo'
                        });
                    }
                }

                criarCelula(linha, cadastro);

                const colunaAcoes = document.createElement('td');

                if (['pendente', 'aberta'].includes(valorStatus)) {
                    const botao = document.createElement('button');

                    botao.className = 'btn btn-sm btn-primary';
                    botao.textContent = demandasEnviando.has(demanda.id) ? 'Enviando...' : 'Processar';
                    botao.disabled = demandasEnviando.has(demanda.id);

                    botao.addEventListener('click', function () {
                        processarDemanda(demanda.id, botao);
                    });

                    colunaAcoes.appendChild(botao);
                } else {
                    colunaAcoes.textContent = '-';
                }

                linha.appendChild(colunaAcoes);

                tabela.appendChild(linha);
            });

            if (dados.demandas.length === 0) {
                const linha = document.createElement('tr');
                const coluna = criarCelula(linha, 'Nenhuma demanda cadastrada');

                coluna.colSpan = 6;
                coluna.className = 'text-center text-muted';

                tabela.appendChild(linha);
            }

            document.getElementById('ultima-atualizacao').textContent =
                'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');

            const conexao = document.getElementById('conexao');
            conexao.className = 'badge bg-success';
            conexao.textContent = 'Atualização automática';
        })
        .catch(error => {
            console.error(error);

            const conexao = document.getElementById('conexao');
            conexao.className = 'badge bg-danger';
            conexao.textContent = 'Falha na atualização';
        })
        .finally(() => {
            atualizando = false;
        });
        
        
    }

    async function processarDemanda(id, botao) {
        if (demandasEnviando.has(id)) {
            return;
        }

        demandasEnviando.add(id);
        botao.disabled = true;
        botao.textContent = 'Enviando...';

        try {
            const response = await fetch("{{ url('/demandas') }}/" + encodeURIComponent(id) + "/processar", {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            });

            const dados = await response.json();

            if (!response.ok || !dados.success) {
                throw new Error(dados.message || 'Erro ao enviar a demanda');
            }

        } catch (error) {
            alert(error.message || 'Não foi possível enviar a demanda.');
        } finally {
            demandasEnviando.delete(id);
            atualizarDemandas();
        }
    }
    
    let monitorandoRabbitMQ = false;

    async function atualizarRabbitMQ() {
        if (monitorandoRabbitMQ) {
            return;
        }

        monitorandoRabbitMQ = true;

        try {
            const response = await fetch("{{ route('demandas.monitoramento') }}", {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Falha ao consultar RabbitMQ');
            }

            const dados = await response.json();

            if (!dados.success) {
                throw new Error('Monitoramento indisponível');
            }

            document.getElementById('fila-processar').textContent =
                dados.filas.processar.total;

            document.getElementById('fila-retry').textContent =
                dados.filas.retry.total;

            document.getElementById('fila-erro').textContent =
                dados.filas.erro.total;

            const status = document.getElementById('status-rabbitmq');

            status.className = 'text-success';
            status.textContent = 'Conectado · ' +
                new Date().toLocaleTimeString('pt-BR');

        } catch (error) {
            console.error(error);

            document.getElementById('fila-processar').textContent = '-';
            document.getElementById('fila-retry').textContent = '-';
            document.getElementById('fila-erro').textContent = '-';

            const status = document.getElementById('status-rabbitmq');

            status.className = 'text-danger';
            status.textContent = 'RabbitMQ indisponível';

        } finally {
            monitorandoRabbitMQ = false;
        }
    }

    atualizarRabbitMQ();

    setInterval(atualizarRabbitMQ, 5000);


    atualizarDemandas();

    setInterval(atualizarDemandas, 3000);

    let reprocessandoDlq = false;

    async function reprocessarDlq() {
        if (reprocessandoDlq) {
            return;
        }

        if (!confirm('Deseja reprocessar a próxima mensagem da fila de erros?')) {
            return;
        }

        reprocessandoDlq = true;

        const botao = document.getElementById('btn-reprocessar');
        const resultado = document.getElementById('resultado-dlq');

        botao.disabled = true;
        botao.textContent = 'Reprocessando...';

        resultado.textContent = '';

        try {
            const response = await fetch("{{ route('demandas.dlq.reprocessar') }}", {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            });

            const dados = await response.json();

            resultado.textContent = dados.message;

            if (!response.ok || !dados.success) {
                resultado.className = 'small mt-2 text-danger';
            } else {
                resultado.className = 'small mt-2 text-success';
            }

        } catch (error) {
            console.error(error);

            resultado.className = 'small mt-2 text-danger';
            resultado.textContent = 'Erro na comunicação com o Laravel.';

        } finally {
            reprocessandoDlq = false;

            botao.disabled = false;
            botao.textContent = 'Reprocessar próxima mensagem da DLQ';

            atualizarRabbitMQ();
            atualizarDemandas();
        }
    }

</script>

</body>
</html>
