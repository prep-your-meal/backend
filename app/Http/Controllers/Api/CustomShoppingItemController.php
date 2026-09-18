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
        summary: 'Add a custom item to a specific week\'s shopping list',
        security: [['bearerAuth' => []]],
        tags: ['Shopping List']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['name', 'week_start'], properties: [
            new OA\Property(property: 'name', type: 'string', example: 'ESN Athlete Stack'),
            new OA\Property(property: 'week_start', type: 'string', format: 'date', example: '2026-09-14'),
        ])
    )]
    #[OA\Response(response: 201, description: 'Item added')]
    public function store(Request $request): JsonResponse
    {
        // 1. Validate the week_start parameter alongside the name
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'week_start' => 'required|date',
        ]);

        // 2. Save the item associated with the specific week
        $item = $request->user()->customShoppingItems()->create([
            'name' => $validated['name'],
            'week_start' => $validated['week_start'],
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
        summary: 'Clear all checked custom items for a specific week',
        security: [['bearerAuth' => []]],
        tags: ['Shopping List']
    )]
    #[OA\Parameter(name: 'week_start', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Completed items cleared')]
    public function clearCompleted(Request $request): JsonResponse
    {
        // 3. Ensure we only clear items for the requested week
        $request->validate([
            'week_start' => 'required|date',
        ]);

        $request->user()->customShoppingItems()
            ->where('week_start', $request->query('week_start'))
            ->where('is_checked', true)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Completed items cleared for the specified week.',
        ]);
    }
}
