<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plantId' => $this->plant_id,
            'machineId' => $this->machine_id,
            'problemType' => $this->problem_type,
            'description' => $this->description,
            'actionTaken' => $this->action_taken,
            'result' => $this->result,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toISOString(),
            'ticket_number' => $this->whenLoaded('maintenanceTicket', fn () => $this->maintenanceTicket?->ticket_number),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
