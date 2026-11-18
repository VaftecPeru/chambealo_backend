<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::with('children')->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function store(CategoryRequest $request)
    {
        $category = Category::create($request->validated());

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function show(Category $category)
    {
        $category->load(['parent', 'children']);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return response()->json(['success' => true, 'data' => $category->fresh()]);
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar: la categoría tiene productos o subcategorías.',
            ], 409);
        }

        $category->delete();

        return response()->json(['success' => true, 'message' => 'Categoría eliminada.']);
    }
}