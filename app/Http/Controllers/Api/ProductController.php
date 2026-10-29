<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:150',
            'category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'in_stock' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Product::query()
            ->active()
            ->with(['brand', 'category', 'media']);

        if (!empty($validated['q'])) {
            $term = $validated['q'];

            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if (!empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (!empty($validated['brand_id'])) {
            $query->where('brand_id', $validated['brand_id']);
        }

        if ($request->boolean('in_stock')) {
            $query->inStock();
        }

        $perPage = (int) ($validated['per_page'] ?? 12);

        $products = $query
    ->orderBy('product_id')
    ->paginate($perPage);

$productList = $products->getCollection()
    ->map(fn (Product $product) => $this->formatProduct($product))
    ->values();

return response()->json([
    'success' => true,
    'data' => $productList,
    'products' => $productList,
    'meta' => [
        'current_page' => $products->currentPage(),
        'last_page' => $products->lastPage(),
        'per_page' => $products->perPage(),
        'total' => $products->total(),
    ],
]);
    }

    public function show(string $product)
    {
        $item = Product::query()
            ->active()
            ->with(['brand', 'category'])
            ->find($product);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatProduct($item),
        ]);
    }

private function formatProduct(Product $product): array
{
    // Obtener la primera imagen asociada al producto.
    $imagePath = $product->media
        ->sortBy('media_id')
        ->first()?->file_url;

    // Convertir la ruta relativa en una URL completa.
    $imageUrl = $imagePath
        ? url($imagePath)
        : null;

    return [
        'id' => $product->product_id,
        'product_id' => $product->product_id,
        'name' => $product->name,
        'slug' => $product->slug,
        'description' => $product->description,

        // Fotografías utilizadas por React.
        'image_url' => $imageUrl,
        'img1' => $imageUrl,

        'price' => (float) $product->price,
        'stock' => (int) $product->stock,
        'status' => $product->status,
        'specifications' => $product->specifications,

        'brand' => $product->brand ? [
            'id' => $product->brand->brand_id,
            'name' => $product->brand->brand_name,
        ] : null,

        'category' => $product->category ? [
            'id' => $product->category->category_id,
            'name' => $product->category->name,
        ] : null,

        'created_at' => $product->created_at?->toISOString(),
    ];
}
    // Operaciones administrativas pendientes de implementar.
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad pendiente de implementar.',
        ], 501);
    }

    public function update(Request $request, string $product)
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad pendiente de implementar.',
        ], 501);
    }

    public function destroy(string $product)
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad pendiente de implementar.',
        ], 501);
    }
}
