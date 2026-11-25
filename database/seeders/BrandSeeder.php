<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = User::where('email', 'vendor@chambealo.pe')->first()
            ?? User::where('role', 'vendor')->first()
            ?? User::first();

        if (!$vendor) {
            return;
        }

        $brands = [
            [
                'brand_name' => 'FerrePro Perú',
                'description' => 'Herramientas eléctricas e industriales de alto rendimiento.',
                'visibility_status' => 'visible',
            ],
            [
                'brand_name' => 'ConstruMax',
                'description' => 'Materiales y equipos para construcción y obra civil.',
                'visibility_status' => 'visible',
            ],
            [
                'brand_name' => 'SeguriWork',
                'description' => 'Equipos de protección personal certificados.',
                'visibility_status' => 'visible',
            ],
        ];

        foreach ($brands as $brandData) {
            Brand::updateOrCreate(
                ['brand_name' => $brandData['brand_name']],
                $brandData + ['user_id' => $vendor->user_id],
            );
        }
    }
}
