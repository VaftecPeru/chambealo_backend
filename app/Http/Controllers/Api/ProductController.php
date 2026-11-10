<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // Listado público: no muestra productos inactivos.
    public function index(Request $request)
    {
        $query = Product::with(['brand', 'category', 'media'])->where('status', '!=', 'inactive');

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->input('brand_id'));
        }
        if ($request->filled('q')) {
            $query->where('name', 'like', '%' . $request->input('q') . '%');
        }

        $perPage = min(max((int) $request->input('per_page', 12), 1), 100);
        $page = $query->orderByDesc('product_id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => ProductResource::collection($page->getCollection())->resolve(),
            'meta'    => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function store(ProductRequest $request)
    {
        $data = $request->validated();

        if (!$this->canManageBrand($request, (int) $data['brand_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado para gestionar productos de esta marca.',
            ], 403);
        }

        $product = Product::create($data);
        $product->load(['brand', 'category', 'media']);

        return response()->json([
            'success' => true,
            'data' => (new ProductResource($product))->resolve(),
        ], 201);
    }

    public function show(Product $product)
    {
        if ($product->status === 'inactive') {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado.'], 404);
        }

        $product->load(['brand', 'category', 'media']);

        return response()->json([
            'success' => true,
            'data'    => (new ProductResource($product))->resolve(),
        ]);
    }

    public function update(ProductRequest $request, Product $product)
    {
        if (!$this->canManageBrand($request, (int) $product->brand_id)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado para modificar este producto.',
            ], 403);
        }

        $data = $request->validated();

        // Un vendedor tampoco puede trasladar su producto a una marca ajena.
        if (isset($data['brand_id'])
            && !$this->canManageBrand($request, (int) $data['brand_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado para utilizar esta marca.',
            ], 403);
        }

        $product->update($data);
        $product->load(['brand', 'category', 'media']);

        return response()->json([
            'success' => true,
            'data' => (new ProductResource($product))->resolve(),
        ]);
    }

    /**
     * Cargar fotografía de un producto (solo administradores).
     */
    public function uploadImage(Request $request, Product $product)
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('image');
        $path = $file->store('products', 'public');

        try {
            $product->media()->create([
                'file_url' => $path,
                'file_type' => $file->getMimeType(),
                'uploaded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            throw $e;
        }

        $product->load(['brand', 'category', 'media']);

        return response()->json([
            'success' => true,
            'message' => 'Imagen guardada correctamente.',
            'data' => (new \App\Http\Resources\ProductResource($product))->resolve(),
        ], 201);
    }

    public function destroy(Request $request, Product $product)
    {
        if (!$this->canManageBrand($request, (int) $product->brand_id)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado para eliminar este producto.',
            ], 403);
        }

        $product->delete();

        return response()->json(['success' => true, 'message' => 'Producto eliminado.']);
    }

    public function myProducts(Request $request)
    {
        $user = $request->user('api') ?? $request->user();

        $products = Product::with(['brand', 'category', 'media'])
            ->whereHas('brand', fn ($query) => $query->where('user_id', $user->user_id))
            ->orderByDesc('product_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products)->resolve(),
        ]);
    }

    private function canManageBrand(Request $request, int $brandId): bool
    {
        $user = $request->user('api') ?? $request->user();

        if ($user->isAdmin()) {
            return true;
        }

        return Brand::whereKey($brandId)
            ->where('user_id', $user->user_id)
            ->exists();
    }

}