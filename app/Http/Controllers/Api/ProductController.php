<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // Listado público: no muestra productos inactivos.
    public function index(Request $request)
    {
        $query = Product::with(['brand', 'category'])->where('status', '!=', 'inactive');

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
        $product = Product::create($request->validated());
        $product->load(['brand', 'category']);

        return response()->json([
            'success' => true,
            'data'    => (new ProductResource($product))->resolve(),
        ], 201);
    }

    public function show(Product $product)
    {
        if ($product->status === 'inactive') {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado.'], 404);
        }

        $product->load(['brand', 'category']);

        return response()->json([
            'success' => true,
            'data'    => (new ProductResource($product))->resolve(),
        ]);
    }

    public function update(ProductRequest $request, Product $product)
    {
        $product->update($request->validated());
        $product->load(['brand', 'category']);

        return response()->json([
            'success' => true,
            'data'    => (new ProductResource($product))->resolve(),
        ]);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['success' => true, 'message' => 'Producto eliminado.']);
    }
}