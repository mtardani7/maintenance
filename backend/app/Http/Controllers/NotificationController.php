<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->paginate(20);
        $notifications->getCollection()->transform(fn ($notification): array => $this->present($notification));

        return response()->json([
            ...$notifications->toArray(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        if ($item->read_at === null) $item->markAsRead();

        return response()->json($this->present($item->fresh()));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        return response()->json(['updated' => true]);
    }

    private function present(object $notification): array
    {
        $data = $notification->data;
        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? 'system',
            'title' => $data['title'] ?? '',
            'body' => $data['message'] ?? '',
            'createdAt' => $notification->created_at?->toISOString(),
            'read' => $notification->read_at !== null,
            'relatedTicketId' => $data['ticket_id'] ?? null,
            'ticketNumber' => $data['ticket_number'] ?? null,
            'machine' => $data['machine'] ?? null,
            'problem' => $data['problem'] ?? null,
            'solution' => $data['solution'] ?? null,
            'closedBy' => $data['closed_by'] ?? null,
            'closedAt' => $data['closed_at'] ?? null,
            'incidentId' => $data['incident_id'] ?? null,
            'url' => $data['url'] ?? null,
        ];
    }
}
