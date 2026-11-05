<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
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
            'created_at'     => $this->created_at,
        ];
    }
}