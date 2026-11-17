<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $product = Product::query()
            ->select(['id', 'name', 'slug'])
            // Fetch the unsigned BIGINT as text before PDO can convert numeric values.
            ->selectRaw('CAST(price AS CHAR) AS price')
            ->where('status', 'published')
            ->whereKey($id)
            ->firstOrFail();

        return response()->json([
            'data' => (new ProductResource($product))->resolve($request),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
        ]);

        $products = Product::query()
            ->select(['id', 'name', 'slug'])
            // Fetch the unsigned BIGINT as text before PDO can convert numeric values.
            ->selectRaw('CAST(price AS CHAR) AS price')
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => ProductResource::collection($products->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }
}
