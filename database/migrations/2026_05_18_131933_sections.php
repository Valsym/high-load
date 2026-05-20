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
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            //$table->uuid('xml_id')->unique()->comment('ID из XML');
            $table->string('name'); // Название раздела
//            $table->uuid('parent_xml_id')->nullable()->comment('XML_ID родительской секции');
//            $table->foreignId('parent_id')->nullable()->constrained('sections'); // ID родительского раздела
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
