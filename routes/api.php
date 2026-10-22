<?php

use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::apiResource('tickets', TicketController::class)->except('update');
Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
