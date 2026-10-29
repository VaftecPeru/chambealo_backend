<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::query()
            ->orderBy('brand_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $brands->map(fn ($brand) => [
                'id' => $brand->brand_id,
                'brand_id' => $brand->brand_id,
                'name' => $brand->brand_name,
                'description' => $brand->description,
                'logo_url' => $brand->logo_url,
            ]),
        ]);
    }

    public function show(string $brand)
    {
        $item = Brand::find($brand);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Marca no encontrada.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item->brand_id,
                'brand_id' => $item->brand_id,
                'name' => $item->brand_name,
                'description' => $item->description,
                'logo_url' => $item->logo_url,
            ],
        ]);
    }

    // Administración pendiente para C-037.
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pendiente de implementar.',
        ], 501);
    }

    public function update(Request $request, string $brand)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pendiente de implementar.',
        ], 501);
    }

    public function destroy(string $brand)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pendiente de implementar.',
        ], 501);
    }
}
