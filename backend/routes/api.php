<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\PlantController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MaintenanceTicketController;
use App\Http\Controllers\MaintenanceDashboardController;
use App\Http\Controllers\MaintenanceTicketSparePartController;
use App\Http\Controllers\QaMachineController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->group(function (): void {
	Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
	Route::get('/notifications', [NotificationController::class, 'index']);
	Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
	Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
	Route::get('/admin/users', [AdminUserController::class, 'index']);
	Route::post('/admin/users', [AdminUserController::class, 'store']);
	Route::put('/admin/users/{user}', [AdminUserController::class, 'update']);
	Route::get('/machines', [MachineController::class, 'index']);
	Route::post('/machines/resolve-qr', [MachineController::class, 'resolveQr']);
	Route::apiResource('plants', PlantController::class)->except(['show']);
	Route::get('/qa-dashboard', [QaMachineController::class, 'dashboard']);
	Route::get('/maintenance-dashboard', MaintenanceDashboardController::class);
	Route::post('/machines', [MachineController::class, 'store']);
	Route::put('/machines/{machine}', [MachineController::class, 'update']);
	Route::patch('/machines/{machine}', [MachineController::class, 'update']);
	Route::delete('/machines/{machine}', [MachineController::class, 'destroy']);
	Route::post('/tickets/{ticket}/actions', [MaintenanceTicketController::class, 'action']);
	Route::post('/tickets/{ticket}/actions/{action}', [MaintenanceTicketController::class, 'action']);
	Route::get('/maintenance-users', [MaintenanceTicketController::class, 'maintenanceUsers']);
	Route::post('/incidents/{incident}/attachments', [AttachmentController::class, 'storeForIncident']);
	Route::post('/tickets/{ticket}/attachments', [AttachmentController::class, 'storeForTicket']);
	Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
	Route::post('/tickets/{ticket}/spare-parts', [MaintenanceTicketSparePartController::class, 'store']);
	Route::delete('/tickets/{ticket}/spare-parts/{sparePart}', [MaintenanceTicketSparePartController::class, 'destroy']);
	Route::apiResource('incidents', IncidentController::class);
	Route::apiResource('tickets', MaintenanceTicketController::class);
});
