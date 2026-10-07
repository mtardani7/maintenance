<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Incident;
use App\Models\MaintenanceTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    private const MAX_ATTACHMENTS = 5;

    private const MAX_TOTAL_KB = 18 * 1024;

    public function storeForIncident(Request $request, Incident $incident): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin' || (string) $incident->reported_by === (string) $request->user()?->id, 403);

        return $this->store($request, ['incident_id' => $incident->id]);
    }

    public function storeForTicket(Request $request, MaintenanceTicket $ticket): JsonResponse
    {
        abort_unless($request->user()?->role === 'technician' || $request->user()?->role === 'admin', 403);
        abort_if($ticket->status === 'CLOSED', 422, 'Closed tickets cannot receive attachments.');

        return $this->store($request, ['maintenance_ticket_id' => $ticket->id]);
    }

    private function store(Request $request, array $parent): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_ATTACHMENTS],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,mp4,mov,webm', 'max:18432'],
        ]);

        $files = $request->file('files');
        if (collect($files)->sum(fn ($file): int => $file->getSize()) > self::MAX_TOTAL_KB * 1024) {
            throw ValidationException::withMessages(['files' => 'Total attachment size must not exceed 18 MB.']);
        }
        $existingCount = Attachment::query()->where($parent)->count();
        if ($existingCount + count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['files' => 'Maximum 5 attachments are allowed.']);
        }

        $attachments = [];
        foreach ($files as $file) {
            $path = $file->store('attachments/'.now()->format('Y/m'), 'local');
            $attachments[] = Attachment::query()->create([
                ...$parent,
                'uploaded_by' => $request->user()->id,
                'file_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$file->guessExtension(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }

        return response()->json(['data' => $attachments], 201);
    }

    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $user = $request->user();
        $allowed = $user?->role === 'admin'
            || ($attachment->incident && (string) $attachment->incident->reported_by === (string) $user?->id)
            || ($attachment->maintenanceTicket && in_array($user?->role, ['technician', 'admin'], true))
            || ($attachment->maintenanceTicket && (string) $attachment->maintenanceTicket->reported_by === (string) $user?->id);
        abort_unless($allowed, 403);

        return Storage::disk('local')->download($attachment->file_path, $attachment->file_name, ['Content-Type' => $attachment->mime_type]);
    }
}
