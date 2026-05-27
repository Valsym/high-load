<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
//            $table->uuid('xml_id')->unique();
            $table->string('name'); // Название товара
            $table->string('code')->nullable(); // Код товара
            $table->longText('description')->nullable(); // Полное описание
            $table->decimal('price', 12, 2)->nullable();  //  цена
            $table->integer('total')->default(0); // Общее количество на складе
            $table->foreignId('section_id')->nullable()->constrained('sections'); // ID раздела
            $table->timestamps();

            // Индексы
            $table->index('code');           // для быстрого поиска по коду
            $table->index('section_id');     // для фильтрации по категории (внешний ключ)
            // Composite index для быстрого поиска и сортировки
            $table->index(['id', 'name', 'code', 'price', 'section_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
