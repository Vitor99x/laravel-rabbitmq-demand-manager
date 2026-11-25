<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class PublicarDemanda extends Command
{
    protected $signature = 'demandas:publicar {id}';

    protected $description = 'Publica uma demanda no RabbitMQ';

    public function handle()
    {
        $id = (int) $this->argument('id');

        if ($id <= 0) {
            $this->error('Informe um ID válido.');
            return 1;
        }

        // 1. Conecta ao RabbitMQ
        $connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password')
        );

        // 2. Cria um canal de comunicação
        $channel = $connection->channel();

        // 3. Declara uma fila durável
        $channel->queue_declare(
            'demandas.processar',
            false,
            true,
            false,
            false
        );

        // 4. Prepara os dados da mensagem
        $dados = [
            'demanda_id' => $id,
            'evento' => 'demanda.criada'
        ];

        // 5. Cria a mensagem
        $mensagem = new AMQPMessage(
            json_encode($dados, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ]
        );

        // 6. Publica na fila pela exchange padrão
        $channel->basic_publish(
            $mensagem,
            '',
            'demandas.processar'
        );

        // 7. Encerra a conexão
        $channel->close();
        $connection->close();

        $this->info('Mensagem publicada no RabbitMQ!');

        return 0;
    }
}
