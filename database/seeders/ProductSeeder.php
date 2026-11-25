<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $ferrePro = Brand::where('brand_name', 'FerrePro Perú')->first() ?? Brand::first();
        $construMax = Brand::where('brand_name', 'ConstruMax')->first() ?? $ferrePro;
        $seguriWork = Brand::where('brand_name', 'SeguriWork')->first() ?? $ferrePro;

        if (!$ferrePro) {
            return;
        }

        $taladros = Category::where('slug', 'taladros-y-rotomartillos')->first() ?? Category::first();
        $sierras = Category::where('slug', 'sierras-y-cortadoras')->first() ?? $taladros;
        $llaves = Category::where('slug', 'sets-de-llaves')->first() ?? $taladros;
        $epp = Category::where('slug', 'proteccion-personal')->first() ?? $taladros;

        if (!$taladros) {
            return;
        }

        $products = [
            [
                'slug' => 'taladro-percutor-20v-ferrepro',
                'brand_id' => $ferrePro->brand_id,
                'category_id' => $taladros->category_id,
                'name' => 'Taladro Percutor Inalámbrico 20V',
                'description' => 'Taladro percutor brushless con 2 baterías de litio y maletín.',
                'price' => 349.90,
                'stock' => 25,
                'status' => 'active',
                'specifications' => ['voltaje' => '20V', 'torque' => '65 Nm', 'baterias' => 2],
            ],
            [
                'slug' => 'rotomartillo-sds-plus-800w',
                'brand_id' => $construMax->brand_id,
                'category_id' => $taladros->category_id,
                'name' => 'Rotomartillo SDS Plus 800W',
                'description' => 'Ideal para perforación en concreto armado y mampostería.',
                'price' => 489.00,
                'stock' => 12,
                'status' => 'active',
                'specifications' => ['potencia' => '800W', 'encastre' => 'SDS Plus'],
            ],
            [
                'slug' => 'sierra-circular-7-1-4-1400w',
                'brand_id' => $ferrePro->brand_id,
                'category_id' => $sierras->category_id,
                'name' => 'Sierra Circular 7-1/4" 1400W',
                'description' => 'Corte preciso en madera y tableros con disco de 24 dientes.',
                'price' => 299.00,
                'stock' => 18,
                'status' => 'active',
                'specifications' => ['potencia' => '1400W', 'disco' => '7-1/4 pulgadas'],
            ],
            [
                'slug' => 'juego-llaves-mixtas-12-piezas',
                'brand_id' => $construMax->brand_id,
                'category_id' => $llaves->category_id,
                'name' => 'Juego de Llaves Mixtas 12 Piezas',
                'description' => 'Acero cromo vanadio de 8mm a 19mm con estuche enrollable.',
                'price' => 89.50,
                'stock' => 40,
                'status' => 'active',
                'specifications' => ['material' => 'Cr-V', 'piezas' => 12],
            ],
            [
                'slug' => 'casco-seguridad-industrial-clase-e',
                'brand_id' => $seguriWork->brand_id,
                'category_id' => $epp->category_id,
                'name' => 'Casco de Seguridad Industrial Clase E',
                'description' => 'Casco dieléctrico con suspensión ratchet de 4 puntos.',
                'price' => 42.00,
                'stock' => 60,
                'status' => 'active',
                'specifications' => ['norma' => 'ANSI Z89.1', 'clase' => 'E'],
            ],
            [
                'slug' => 'guantes-anticorte-nivel-5',
                'brand_id' => $seguriWork->brand_id,
                'category_id' => $epp->category_id,
                'name' => 'Guantes Anticorte Nivel 5',
                'description' => 'Recubrimiento de nitrilo arenoso para agarre en seco y húmedo.',
                'price' => 28.90,
                'stock' => 0,
                'status' => 'active',
                'specifications' => ['nivel_corte' => '5', 'talla' => 'L'],
            ],
        ];

        foreach ($products as $productData) {
            Product::updateOrCreate(
                ['slug' => $productData['slug']],
                $productData,
            );
        }
    }
}
