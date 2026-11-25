<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $herramientas = Category::updateOrCreate(
            ['slug' => 'herramientas-electricas'],
            [
                'name' => 'Herramientas Eléctricas',
                'description' => 'Taladros, amoladoras, sierras y herramientas con motor.',
                'parent_id' => null,
            ],
        );

        $manuales = Category::updateOrCreate(
            ['slug' => 'herramientas-manuales'],
            [
                'name' => 'Herramientas Manuales',
                'description' => 'Llaves, desarmadores, martillos y sets de mano.',
                'parent_id' => null,
            ],
        );

        $seguridad = Category::updateOrCreate(
            ['slug' => 'seguridad-industrial'],
            [
                'name' => 'Seguridad Industrial',
                'description' => 'Cascos, guantes, lentes y calzado de seguridad.',
                'parent_id' => null,
            ],
        );

        $subcategories = [
            [
                'slug' => 'taladros-y-rotomartillos',
                'name' => 'Taladros y Rotomartillos',
                'description' => 'Taladros percutores, inalámbricos y rotomartillos SDS.',
                'parent_id' => $herramientas->category_id,
            ],
            [
                'slug' => 'sierras-y-cortadoras',
                'name' => 'Sierras y Cortadoras',
                'description' => 'Sierras circulares, caladoras y cortadoras de concreto.',
                'parent_id' => $herramientas->category_id,
            ],
            [
                'slug' => 'sets-de-llaves',
                'name' => 'Sets de Llaves',
                'description' => 'Juegos de llaves mixtas, dados y trinquetes.',
                'parent_id' => $manuales->category_id,
            ],
            [
                'slug' => 'proteccion-personal',
                'name' => 'Protección Personal (EPP)',
                'description' => 'Implementos de protección para obra y taller.',
                'parent_id' => $seguridad->category_id,
            ],
        ];

        foreach ($subcategories as $sub) {
            Category::updateOrCreate(['slug' => $sub['slug']], $sub);
        }
    }
}
