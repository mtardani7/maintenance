<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\PlantController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MaintenanceTicketController;
use App\Http\Controllers\MaintenanceTicketSparePartController;
use App\Http\Controllers\QaMachineController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->group(function (): void {
	Route::get('/admin/users', [AdminUserController::class, 'index']);
	Route::post('/admin/users', [AdminUserController::class, 'store']);
	Route::put('/admin/users/{user}', [AdminUserController::class, 'update']);
	Route::get('/machines', [MachineController::class, 'index']);
	Route::apiResource('plants', PlantController::class)->except(['show']);
	Route::get('/qa-dashboard', [QaMachineController::class, 'dashboard']);
	Route::post('/machines', [MachineController::class, 'store']);
	Route::put('/machines/{machine}', [MachineController::class, 'update']);
	Route::patch('/machines/{machine}', [MachineController::class, 'update']);
	Route::delete('/machines/{machine}', [MachineController::class, 'destroy']);
	Route::post('/tickets/{ticket}/actions', [MaintenanceTicketController::class, 'action']);
	Route::post('/tickets/{ticket}/actions/{action}', [MaintenanceTicketController::class, 'action']);
	Route::get('/maintenance-users', [MaintenanceTicketController::class, 'maintenanceUsers']);
	Route::post('/tickets/{ticket}/spare-parts', [MaintenanceTicketSparePartController::class, 'store']);
	Route::delete('/tickets/{ticket}/spare-parts/{sparePart}', [MaintenanceTicketSparePartController::class, 'destroy']);
	Route::apiResource('incidents', IncidentController::class);
	Route::apiResource('tickets', MaintenanceTicketController::class);
});
