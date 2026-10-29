<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories->map(fn ($category) => [
                'id' => $category->category_id,
                'category_id' => $category->category_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'parent_id' => $category->parent_id,
            ]),
        ]);
    }

    public function show(string $category)
    {
        $item = Category::find($category);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item->category_id,
                'category_id' => $item->category_id,
                'name' => $item->name,
                'slug' => $item->slug,
                'description' => $item->description,
                'parent_id' => $item->parent_id,
            ],
        ]);
    }

    // Las operaciones administrativas se implementarán después.
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pendiente de implementar.',
        ], 501);
    }

    public function update(Request $request, string $category)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pendiente de implementar.',
        ], 501);
    }

    public function destroy(string $category)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pendiente de implementar.',
        ], 501);
    }
}
