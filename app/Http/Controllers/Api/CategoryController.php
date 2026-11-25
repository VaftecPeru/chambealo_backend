<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function index()
    {
        $categories = Category::with('children')->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function store(CategoryRequest $request)
    {
        $category = Category::create($request->validated());

        $this->auditService->recordModel(
            $category,
            'created',
            null,
            $category->toArray(),
            "Categoría '{$category->name}' creada",
            $request,
        );

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function show(Category $category)
    {
        $category->load(['parent', 'children']);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $oldValues = $category->toArray();
        $category->update($request->validated());
        $fresh = $category->fresh();

        $this->auditService->recordModel(
            $fresh,
            'updated',
            $oldValues,
            $fresh->toArray(),
            "Categoría '{$fresh->name}' actualizada",
            $request,
        );

        return response()->json(['success' => true, 'data' => $fresh]);
    }

    public function destroy(Request $request, Category $category)
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar: la categoría tiene productos o subcategorías.',
            ], 409);
        }

        $oldValues = $category->toArray();
        $category->delete();

        $this->auditService->recordModel(
            $category,
            'deleted',
            $oldValues,
            null,
            "Categoría '{$category->name}' eliminada",
            $request,
        );

        return response()->json(['success' => true, 'message' => 'Categoría eliminada.']);
    }
}
