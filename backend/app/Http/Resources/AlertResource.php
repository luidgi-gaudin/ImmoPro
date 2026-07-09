<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'message' => $this->message,
            'due_date' => $this->due_date?->toDateString(),
            'meta' => $this->meta,
            // On expose seulement le nom court du modèle lié (Lease, RentPayment, Property).
            'subject_type' => $this->alertable_type ? class_basename($this->alertable_type) : null,
            'subject_id' => $this->alertable_id,
            'is_read' => $this->read_at !== null,
            'is_resolved' => $this->resolved_at !== null,
            'reminded_at' => $this->reminded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
