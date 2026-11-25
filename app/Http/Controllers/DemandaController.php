<?php

namespace App\Http\Controllers;

use App\Models\Demanda;
use Illuminate\Http\Request;
use App\Services\RabbitMQService;
use App\Services\RabbitMQDlqService;
use Illuminate\Support\Facades\Http;


class DemandaController extends Controller
{
    public function index(){
        return view('demandas.index');
    }

    public function dados(){
        $demandas = Demanda::orderBy('id', 'desc')->get();

        $total = $demandas->count();

        $pendentes = $demandas->filter(function ($demanda) {
            return in_array(strtolower(trim($demanda->status)), ['pendente', 'aberta']);
        })->count();

        $processando = $demandas->filter(function ($demanda) {
            return in_array(strtolower(trim($demanda->status)), ['enfileirada', 'processando', 'em andamento']);
        })->count();

        $finalizadas = $demandas->filter(function ($demanda) {
            return strtolower(trim($demanda->status)) === 'finalizada';
        })->count();

        return response()->json([
            'total' => $total,
            'pendentes' => $pendentes,
            'processando' => $processando,
            'finalizadas' => $finalizadas,
            'demandas' => $demandas
        ]);
    }

    public function store(Request $request, RabbitMQService $rabbitMQ){

        $request->validate([
            'titulo' => 'required|string|max:255',
            'descricao' => 'nullable|string'
        ]);

        $demanda = Demanda::create([
            'titulo' => $request->titulo,
            'descricao' => $request->descricao,
            'status' => 'enfileirada'
        ]);

        try {

            $rabbitMQ->publicarDemanda($demanda);

            return redirect()->route('demandas.index')
                ->with('success', 'Demanda cadastrada e enviada para processamento.');

        }catch(\Throwable $e){

            report($e);

            Demanda::where('id', $demanda->id)
                ->where('status', 'enfileirada')
                ->update(['status' => 'verificar_envio']);

            return redirect()->route('demandas.index')
                ->with('error', 'Demanda cadastrada, mas não foi possível confirmar o envio ao RabbitMQ.');
        }
    }

    public function processar(Demanda $demanda, RabbitMQService $rabbitMQ){

        $atualizado = Demanda::where('id', $demanda->id)
            ->whereIn('status', ['pendente', 'aberta', 'Aberta'])
            ->update(['status' => 'enfileirada']);

        if(!$atualizado){
            return response()->json([
                'success' => false,
                'message' => 'Essa demanda já foi enviada ou não está disponível para processamento.'
            ], 409);
        }

        try {

            $rabbitMQ->publicarDemanda($demanda);

            return response()->json([
                'success' => true,
                'message' => 'Demanda enviada para processamento.'
            ]);

        }catch(\Throwable $e){

            report($e);

            Demanda::where('id', $demanda->id)
                ->where('status', 'enfileirada')
                ->update(['status' => 'verificar_envio']);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível confirmar o envio ao RabbitMQ. Verifique a fila antes de tentar novamente.'
            ], 503);
        }
    }


    public function monitoramento(){

        $filas = [
            'processar' => config('rabbitmq.queue'),
            'retry' => 'demandas.retry',
            'erro' => 'demandas.erro'
        ];

        $resultado = [];

        try {

            foreach ($filas as $tipo => $nome) {

                $url = rtrim(config('rabbitmq.management_url'), '/') .'/api/queues/' . rawurlencode(config('rabbitmq.vhost')) .'/' . rawurlencode($nome);

                $resposta = Http::withBasicAuth(
                    config('rabbitmq.user'),
                    config('rabbitmq.password')
                )->acceptJson()->timeout(5)->get($url);

                if (!$resposta->successful()) {
                    throw new \Exception(
                        'Falha ao consultar ' . $nome .
                        ' (HTTP ' . $resposta->status() . ')'
                    );
                }

                $dados = $resposta->json();

                $resultado[$tipo] = [
                    'nome' => $nome,
                    'ready' => $dados['messages_ready'] ?? 0,
                    'unacked' => $dados['messages_unacknowledged'] ?? 0,
                    'total' => $dados['messages'] ?? 0
                ];
            }

            return response()->json([
                'success' => true,
                'filas' => $resultado
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível consultar o RabbitMQ.'
            ], 503);
        }
    }

    public function reprocessarDlq(RabbitMQDlqService $rabbitMQDlq){

        if (!app()->environment('local')) {
            abort(403);
        }

        try {
            
            $resultado = $rabbitMQDlq->reprocessar();

            return response()->json(
                $resultado,
                $resultado['success'] ? 200 : 422
            );

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao reprocessar mensagem. Verifique o RabbitMQ.'
            ], 503);
        }
    }

}
