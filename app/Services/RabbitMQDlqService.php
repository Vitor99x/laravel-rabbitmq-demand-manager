<?php

namespace App\Services;

use App\Models\Demanda;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQDlqService
{
    public function reprocessar()
    {
        $conexao = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password')
        );

        $canal = $conexao->channel();

        try {
            
            $fila = config('rabbitmq.queue');

            $canal->queue_declare('demandas.erro', false, true, false, false);
            $canal->queue_declare($fila, false, true, false, false);

            $mensagem = $canal->basic_get('demandas.erro', false);

            if (!$mensagem) {
                return [
                    'success' => false,
                    'message' => 'Não existem mensagens na fila de erros.'
                ];
            }

            $dados = json_decode($mensagem->body, true);

            if (
                !is_array($dados) ||
                !isset($dados['id']) ||
                filter_var($dados['id'], FILTER_VALIDATE_INT) === false ||
                (int) $dados['id'] <= 0
            ) {
                $mensagem->nack(false, true);

                return [
                    'success' => false,
                    'message' => 'Mensagem inválida. Mantida na DLQ.'
                ];
            }

            $demanda = Demanda::find($dados['id']);

            if (!$demanda) {
                $mensagem->nack(false, true);

                return [
                    'success' => false,
                    'message' => 'Demanda não encontrada. Mensagem mantida na DLQ.'
                ];
            }

            if (strtolower(trim($demanda->status)) === 'finalizada') {
                $mensagem->ack();

                return [
                    'success' => true,
                    'message' => 'Demanda já finalizada. Mensagem duplicada removida da DLQ.'
                ];
            }

            $dados['_tentativa'] = 1;

            unset($dados['simular_falha']);

            $novaMensagem = new AMQPMessage(
                json_encode($dados, JSON_THROW_ON_ERROR),
                [
                    'delivery_mode' => 2,
                    'content_type' => 'application/json'
                ]
            );

            $canal->confirm_select();

            $canal->basic_publish(
                $novaMensagem,
                '',
                $fila,
                true
            );

            $canal->wait_for_pending_acks_returns(5);

            $mensagem->ack();

            return [
                'success' => true,
                'message' => 'Demanda ' . $demanda->id . ' reenviada para processamento.'
            ];

        } finally {
            if ($canal->is_open()) {
                $canal->close();
            }

            $conexao->close();
        }
    }
}
