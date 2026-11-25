<?php

namespace App\Console\Commands;

use App\Models\Demanda;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ReprocessarDemandas extends Command
{
    protected $signature = 'demandas:reprocessar';

    protected $description = 'Reprocessa mensagens da fila de erros';

    public function handle()
    {
        $conexao = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password')
        );

        $canal = $conexao->channel();

        $canal->queue_declare('demandas.erro', false, true, false, false);
        $canal->queue_declare(config('rabbitmq.queue'), false, true, false, false);

        $canal->confirm_select();

        $this->info('Consultando fila de erros...');

        $quantidade = 0;

        try {
            while (true) {
                $mensagem = $canal->basic_get('demandas.erro', false);

                if (!$mensagem) {
                    break;
                }

                $dados = json_decode($mensagem->body, true);

                if (
                    !is_array($dados) ||
                    !isset($dados['id']) ||
                    filter_var($dados['id'], FILTER_VALIDATE_INT) === false ||
                    (int) $dados['id'] <= 0
                ) {
                    $this->error('Mensagem inválida encontrada na DLQ.');
                    $mensagem->nack(false, true);
                    break;
                }

                $demanda = Demanda::find($dados['id']);

                if (!$demanda) {
                    $this->error('Demanda não encontrada: ' . $dados['id']);
                    $mensagem->nack(false, true);
                    break;
                }

                if (strtolower(trim($demanda->status)) === 'finalizada') {
                    $this->warn('Demanda já finalizada: ' . $demanda->id);
                    $mensagem->ack();
                    continue;
                }

                $dados['_tentativa'] = 1;

                unset($dados['simular_falha']);

                $novaMensagem = new AMQPMessage(
                    json_encode($dados),
                    [
                        'delivery_mode' => 2,
                        'content_type' => 'application/json'
                    ]
                );

                try {
                    $canal->basic_publish(
                        $novaMensagem,
                        '',
                        config('rabbitmq.queue'),
                        true
                    );

                    $canal->wait_for_pending_acks_returns(5);

                    $mensagem->ack();

                    $quantidade++;

                    $this->info(
                        'Demanda reenviada para processamento: ' .
                        $demanda->id
                    );

                } catch (\Throwable $e) {
                    $this->error('Erro na republicação: ' . $e->getMessage());

                    report($e);

                    if ($canal->is_open()) {
                        $mensagem->nack(false, true);
                    }

                    break;
                }
            }
        } finally {
            if ($canal->is_open()) {
                $canal->close();
            }

            $conexao->close();
        }

        $this->info('Total de demandas reenviadas: ' . $quantidade);

        return 0;
    }
}
