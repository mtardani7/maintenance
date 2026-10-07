<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'maintenance_ticket_id' => $this->maintenance_ticket_id,
            'uploaded_by' => $this->uploaded_by,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'url' => '/attachments/'.$this->id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
