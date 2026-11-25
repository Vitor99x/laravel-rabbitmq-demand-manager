<?php

use App\Http\Controllers\DemandaController;

Route::get('/demandas', [DemandaController::class, 'index'])->name('demandas.index');

Route::get('/demandas/dados', [DemandaController::class, 'dados'])->name('demandas.dados');

Route::post('/demandas', [DemandaController::class, 'store'])->name('demandas.store');

Route::post('/demandas/{demanda}/processar', [DemandaController::class, 'processar'])->name('demandas.processar');

Route::get('/demandas/monitoramento', [DemandaController::class, 'monitoramento'])->name('demandas.monitoramento');

Route::post('/demandas/dlq/reprocessar', [DemandaController::class, 'reprocessarDlq'])->name('demandas.dlq.reprocessar');