<?php

namespace App\Http\Resources;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'price' => (float)$this->price,
            //'description' => $this->description,
            'total' => $this->total,
            'section' => [
                'id' => $this->section_id,
                'name' => $this->section->name ?? null,
            ]
            //'section' => $this->section,
            //'section' => Section::find($this->section_id), // N+1
        ];
    }
}

