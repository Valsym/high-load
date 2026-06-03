<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // с пагинацией
        return ProductResource::collection(
            Product::with('section')->paginate(12));
    }

    public function showByCode($code)
    {
        $product = Product::with('section')
            ->where('code', $code)->firstOrFail();

        return new ProductResource($product);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $product = Product::with('section')->findOrFail($id);

        return new ProductResource($product);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:products,code',
            'description' => 'nullable|string',
            'section_id' => 'required|exists:sections,id',
            'total' => 'required|integer|min:0',
            'price' => 'required|numeric|min:0',
        ]);

        $product = Product::create($validated);
        $product->load('section');

        // Отправляем сообщение в Kafka через Kafka::publish()
        $this->publishProductChange($product, 'created');

        return new ProductResource($product);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:255|unique:products,code,' . $product->id,
            'description' => 'nullable|string',
            'section_id' => 'sometimes|exists:sections,id',
            'total' => 'sometimes|integer|min:0',
            'price' => 'sometimes|numeric|min:0',
        ]);

        $product->update($validated);
        $product->load('section');

        // Отправляем сообщение в Kafka через Kafka::publish()
        $this->publishProductChange($product, 'updated');

        return new ProductResource($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $sectionName = $product->section?->name ?? 'unknown';
        $productData = [
            'product_id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'price' => (float) $product->price,
            'total' => $product->total,
            'section_id' => $product->section_id,
            'section_name' => $sectionName,
        ];

        $product->delete();

        // Отправляем сообщение в Kafka через Kafka::publish()
        $this->sendToKafka([
            'action' => 'deleted',
            'product' => $productData,
        ]);

        return response()->json(['message' => 'Product deleted']);
    }

    /**
     * Отправить сообщение об изменении продукта в Kafka.
     */
    private function publishProductChange(Product $product, string $action): void
    {
        $this->sendToKafka([
            'action' => $action,
            'product' => [
                'product_id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'price' => (float) $product->price,
                'total' => $product->total,
                'section_id' => $product->section_id,
                'section_name' => $product->section?->name ?? 'unknown',
            ],
        ]);
    }

    /**
     * Отправить сообщение в Kafka топик product_changes через Kafka::publish().
     */
    private function sendToKafka(array $payload): void
    {
        try {
            $message = new Message(
                body: $payload
            );

            Kafka::publish()
                ->onTopic('product_changes')
                ->withMessage($message)
                ->send();

            \Log::info('Published to Kafka: ' . json_encode($payload));
        } catch (\Exception $e) {
            \Log::warning('Failed to publish product change to Kafka: ' . $e->getMessage());
        }
    }
}
