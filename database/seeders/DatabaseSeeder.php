<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Section::factory(100)->create();
        $sections = Section::all();
        $totalSections = $sections->count();
        echo "Создано категорий: $totalSections\n";

//        $globalCounter = 1;
        DB::transaction(function () use ($sections) {
            $globalCounter = 1;
            foreach ($sections as $section) {
                $products = [];
                for ($i = 1; $i <= 100; $i++) {
                    $products[] = [
                        'name' => 'Product ' . ($globalCounter++),
                        'code' => fake()->numerify('#######'),
                        'price' => rand(101, 29900),
                        'total' => rand(1, 100),
                        'section_id' => $section->id,
                        'description' => 'Lorem Ipsum.',
                    ];
                }
                Product::insert($products);
                unset($products); // освободить память
                gc_collect_cycles();

                // Прогресс
                Log::info("Вставлено продуктов: " . ($globalCounter - 1) . "\n");
            }
        });

//        foreach ($sections as $section) {
//            $products = [];
//            for ($i = 1; $i <= 1000; $i++) {
//                $products[] = [
//                    'name' => 'Product ' . ($globalCounter++),
//                    'code' => fake()->numerify('#######'),
//                    'price' => rand(101, 29900),
//                    'total' => rand(1, 100),
//                    'section_id' => $section->id,
//                    'description' => 'это "текст-рыба", часто используемый в печати и веб-дизайне. Lorem Ipsum является стандартной "рыбой" для текстов на латинице с начала XVI века. В то время некий безымянный печатник создал большую коллекцию размеров и форм шрифтов, используя Lorem Ipsum для распечатки образцов. Lorem Ipsum не только успешно пережил без заметных изменений пять веков, но и перешагнул в электронный дизайн.',
//                ];
//            }
//            Product::insert($products);
//        }
//        foreach ($sections as $index => $section) {
//            $products = [];
//            for ($i = 1; $i <= 1000; $i++) {
//                $products[] = [
//                    'name' => "Product of {$section->name} #{$i}",
//                    'code' => fake()->numerify('#######'),
//                    'price' => rand(101, 29900),
//                    'total' => rand(1, 100),
//                    'section_id' => $section->id,
//                    'description' => 'это "текст-рыба", часто используемый в печати и веб-дизайне. Lorem Ipsum является стандартной "рыбой" для текстов на латинице с начала XVI века. В то время некий безымянный печатник создал большую коллекцию размеров и форм шрифтов, используя Lorem Ipsum для распечатки образцов. Lorem Ipsum не только успешно пережил без заметных изменений пять веков, но и перешагнул в электронный дизайн.',
//                ];
//            }
//            Product::insert($products);
//        }
//        foreach ($sections as $section) {
//            $products = Product::factory(10)->make()->toArray();
//            foreach ($products as &$product) {
//                $product['section_id'] = $section->id;
//            }
//            Product::insert($products); // один запрос на 1000 продуктов
//        }
//        foreach ($sections as $section) {
//            Product::factory(10)->make()->each(function ($product) use ($section) {
//                $product->section_id = $section->id;
//                $product->save();
//            });
//        }
    }
}
