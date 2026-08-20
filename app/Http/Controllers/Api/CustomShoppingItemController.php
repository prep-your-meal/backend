<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomShoppingItemController extends Controller
{
    #[OA\Post(
        path: '/shopping-list/custom',
        summary: 'Add a custom item to the shopping list',
        security: [['bearerAuth' => []]],
        tags: ['Shopping List']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['name'], properties: [
            new OA\Property(property: 'name', type: 'string', example: 'ESN Athlete Stack'),
        ])
    )]
    #[OA\Response(response: 201, description: 'Item added')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $item = $request->user()->customShoppingItems()->create([
            'name' => $validated['name'],
            'is_checked' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $item,
        ], 201);
    }

    #[OA\Put(
        path: '/shopping-list/custom/{id}/toggle',
        summary: 'Toggle the checked status of a custom item',
        security: [['bearerAuth' => []]],
        tags: ['Shopping List']
    )]
    #[OA\Response(response: 200, description: 'Status toggled')]
    public function toggle(Request $request, int $id): JsonResponse
    {
        $item = $request->user()->customShoppingItems()->findOrFail($id);

        $item->update([
            'is_checked' => ! $item->is_checked,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $item,
        ]);
    }

    #[OA\Delete(
        path: '/shopping-list/custom/{id}',
        summary: 'Delete a specific custom item',
        security: [['bearerAuth' => []]],
        tags: ['Shopping List']
    )]
    #[OA\Response(response: 200, description: 'Item deleted')]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->customShoppingItems()->findOrFail($id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Item deleted.',
        ]);
    }

    #[OA\Delete(
        path: '/shopping-list/custom/completed',
        summary: 'Clear all checked custom items',
        security: [['bearerAuth' => []]],
        tags: ['Shopping List']
    )]
    #[OA\Response(response: 200, description: 'Completed items cleared')]
    public function clearCompleted(Request $request): JsonResponse
    {
        $request->user()->customShoppingItems()->where('is_checked', true)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Completed items cleared.',
        ]);
    }
}
