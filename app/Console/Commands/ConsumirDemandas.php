<?php

namespace App\Console\Commands;


use App\Models\Demanda;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumirDemandas extends Command
{
    protected $signature = 'demandas:consumir';

    protected $description = 'Consome demandas com retry, DLQ e controle de concorrência';

    public function handle()
    {
        $conexao = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password')
        );

        $canal = $conexao->channel();

        $fila = config('rabbitmq.queue');

        $canal->queue_declare($fila, false, true, false, false);
        $canal->queue_declare('demandas.retry', false, true, false, false);

        $canal->basic_qos(null, 1, null);
        $canal->confirm_select();

        $this->info('Aguardando demandas...');

        $canal->basic_consume($fila, '', false, false, false, false, function ($mensagem) use ($canal){

                $dados = json_decode($mensagem->body, true);

                if (!is_array($dados) || !isset($dados['id']) ||filter_var($dados['id'], FILTER_VALIDATE_INT) === false || (int) $dados['id'] <= 0){

                    $this->error('Mensagem inválida');
                    $mensagem->nack(false, false);
                    return;
                }

                $id = (int) $dados['id'];

                $tentativa = isset($dados['_tentativa']) ? (int) $dados['_tentativa'] : 1;

                $nomeLock = 'demanda_' . $id;
                $lockObtido = false;

                try {

                    $resultado = DB::selectOne('SELECT GET_LOCK(?, 0) AS bloqueado',[$nomeLock]);

                    if ((int) $resultado->bloqueado !== 1){

                        $this->warn('Demanda ' . $id . ' já está sendo processada.');

                        sleep(1);

                        $mensagem->nack(false, true);
                        return;
                    }

                    $lockObtido = true;

                    $demanda = Demanda::findOrFail($id);

                    if (strtolower(trim($demanda->status)) === 'finalizada'){

                        $this->info('Demanda já finalizada: ' . $id);
                        $mensagem->ack();
                        return;

                    }

                    $this->info('Processando demanda: ' . $id . ' | Tentativa: ' . $tentativa . '/3');

                    if (!empty($dados['simular_falha'])){
                        throw new \RuntimeException('Falha temporária simulada');
                    }

                    $demanda->status = 'processando';
                    $demanda->save();

                    sleep(3);

                    $demanda->status = 'finalizada';
                    $demanda->save();

                    $this->info('Demanda finalizada: ' . $id);

                    $mensagem->ack();

                }catch(\Throwable $e){

                    $this->error('Erro: ' . $e->getMessage());
                    report($e);

                    $temporario = $e instanceof QueryException || $e instanceof \PDOException || ($e instanceof \RuntimeException && $e->getMessage() === 'Falha temporária simulada');

                    if(!$temporario || $tentativa >= 3){

                        $this->warn('Encaminhando para demandas.erro');
                        $mensagem->nack(false, false);

                    }else{

                        $dados['_tentativa'] = $tentativa + 1;

                        try {

                            $novaMensagem = new AMQPMessage(json_encode($dados),['delivery_mode' => 2,'content_type' => 'application/json']);

                            $canal->basic_publish($novaMensagem,'','demandas.retry',true);

                            $canal->wait_for_pending_acks_returns(5);

                            $mensagem->ack();

                            $this->warn('Enviada para retry. Próxima tentativa: ' . $dados['_tentativa'] . '/3');

                        } catch (\Throwable $erro){

                            $this->error('Erro ao publicar retry: ' . $erro->getMessage());

                            report($erro);

                            if ($canal->is_open()){
                                $mensagem->nack(false, true);
                            }
                        }
                    }

                }finally{

                    if ($lockObtido){
                        try {

                            DB::select('SELECT RELEASE_LOCK(?)',[$nomeLock]);

                        } catch (\Throwable $e){

                            report($e);
                            $this->error('Erro ao liberar bloqueio: ' . $e->getMessage());

                        }
                    }
                }
            }
        );

        try{

            while ($canal->is_consuming()) {

                $canal->wait();

            }

        }finally{

            if ($canal->is_open()) {

                $canal->close();

            }

            $conexao->close();
        }
    }
}