<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    public function publicarDemanda($demanda)
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

            $canal->queue_declare($fila, false, true, false, false);
            $canal->confirm_select();

            $mensagem = new AMQPMessage(
                json_encode([
                    'id' => $demanda->id,
                    'titulo' => $demanda->titulo,
                    'descricao' => $demanda->descricao,
                    '_tentativa' => 1
                ]),
                [
                    'delivery_mode' => 2,
                    'content_type' => 'application/json'
                ]
            );

            $canal->basic_publish($mensagem, '', $fila, true);

            $canal->wait_for_pending_acks_returns(5);

        } finally {
            if ($canal->is_open()) {
                $canal->close();
            }

            $conexao->close();
        }
    }
}
