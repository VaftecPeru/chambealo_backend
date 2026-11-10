<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        $picture = $this->resource->relationLoaded('media')
            ? $this->resource->media
                ->filter(fn ($item) => str_starts_with((string) $item->file_type, 'image/'))
                ->sortByDesc('media_id')
                ->first()
            : $this->resource->media()
                ->where('file_type', 'like', 'image/%')
                ->orderByDesc('media_id')
                ->first();

        $imageUrl = null;
        if ($picture && $picture->file_url) {
            $storedPath = (string) $picture->file_url;
            $imageUrl = preg_match('~^https?://~i', $storedPath)
                ? $storedPath
                : url(\Illuminate\Support\Facades\Storage::disk('public')->url($storedPath));
        }

        return [
            'id'             => $this->product_id,
            'product_id'     => $this->product_id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'price'          => (float) $this->price,
            'stock'          => (int) $this->stock,
            'status'         => $this->status,
            'specifications' => $this->specifications,
            'brand'          => $this->whenLoaded('brand', fn () => [
                'id'   => $this->brand->brand_id,
                'name' => $this->brand->name,
            ]),
            'category'       => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->category_id,
                'name' => $this->category->name,
            ]),
            'image_url'      => $imageUrl,
            'created_at'     => $this->created_at,
        ];
    }
}