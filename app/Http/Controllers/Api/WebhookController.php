<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class WebhookController extends Controller
{
    #[OA\Post(
        path: '/webhooks/github',
        summary: 'Synchronize recipes from GitHub',
        tags: ['System']
    )]
    #[OA\Parameter(
        name: 'X-PYM-SYNC-TOKEN',
        in: 'header',
        required: true,
        description: 'HMAC hex digest of the payload',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'Successful synchronization')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function handle(Request $request)
    {
        try {
            $this->verifyRecipeSyncSecret($request);
            $storageBasePath = storage_path('app');

            // 1. Download & extract repo
            $this->downloadAndExtractRepo($storageBasePath);

            // 2. Sync ingredients (Master Registry)
            $this->syncMasterIngredients($storageBasePath.'/recipes/ingredients.yaml');

            // 3. Parse recipe bundles
            $this->parseRecipesFromDirectory($storageBasePath);
            $parsedRecipes = $this->parseRecipesFromDirectory($storageBasePath);

            // 4. Sync recipes to DB
            $this->syncRecipesToDatabase($parsedRecipes);

            return response()->json([
                'status' => 'success',
                'message' => 'Successfully synced '.count($parsedRecipes).' recipe bundles and master ingredients to the database.',
            ]);

        } catch (\Throwable $e) {
            Log::error('GitHub Sync Error: '.$e->getMessage());

            $code = $e->getCode();
            $statusCode = (is_numeric($code) && $code >= 400 && $code < 600) ? (int) $code : 500;

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync recipes.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], $statusCode);
        }
    }

    private function verifyRecipeSyncSecret(Request $request): void
    {
        $secret = config('services.github.sync_secret');

        if (empty($secret)) {
            throw new \Exception('Sync secret is not configured on the server.', 500);
        }

        $token = $request->header('X-PYM-SYNC-TOKEN');

        if (empty($token) || ! hash_equals($secret, $token)) {
            throw new \Exception('Unauthorized. Invalid or missing sync token.', 401);
        }
    }

    private function downloadAndExtractRepo(string $destinationPath): void
    {
        $repo = trim(config('services.github.repo'));
        $branch = trim(config('services.github.branch', 'main'));
        $token = trim(config('services.github.token'));

        $url = "https://api.github.com/repos/{$repo}/zipball/{$branch}";
        $zipPath = storage_path('app/temp_repo.zip');
        $tempExtractPath = storage_path('app/temp_extracted');

        $response = Http::withToken($token)
            ->withHeaders([
                'User-Agent' => 'PrepYourMeal-Webhook-App',
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->get($url);

        if ($response->failed()) {
            throw new \Exception('Failed to download repository. HTTP Status: '.$response->status());
        }

        File::put($zipPath, $response->body());

        $zip = new ZipArchive;
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($tempExtractPath);
            $zip->close();

            $directories = File::directories($tempExtractPath);

            if (! empty($directories)) {
                $repoRoot = $directories[0];
                $destinationPath = storage_path('app/recipes');

                // 1. Copy ingredients.yaml
                $ingredientsSource = $repoRoot.'/ingredients.yaml';
                if (File::exists($ingredientsSource)) {
                    File::copy($ingredientsSource, $destinationPath.'/ingredients.yaml');
                }

                // 2. Copy categories.yaml (Schema Contract)
                $categoriesSource = $repoRoot.'/categories.yaml';
                if (File::exists($categoriesSource)) {
                    File::copy($categoriesSource, $destinationPath.'/categories.yaml');
                    Cache::forget('pym_categories_schema');
                }

                // 3. Copy markdown recipes (Bundles)
                $recipesSource = $repoRoot.'/recipes';
                if (File::exists($recipesSource)) {
                    File::ensureDirectoryExists($destinationPath.'/recipes');
                    File::copyDirectory($recipesSource, $destinationPath.'/recipes');

                    // 4. Extract images from bundles and move them to public folder
                    $publicImagesPath = public_path('recipes/images');
                    File::ensureDirectoryExists($publicImagesPath);

                    $bundles = File::directories($recipesSource);
                    foreach ($bundles as $bundle) {
                        $slug = basename($bundle);
                        $imageSource = $bundle.'/image.webp';
                        if (File::exists($imageSource)) {
                            File::copy($imageSource, $publicImagesPath.'/'.$slug.'.webp');
                        }
                    }
                }
            }

            File::delete($zipPath);
            File::deleteDirectory($tempExtractPath);
        } else {
            throw new \Exception('Failed to open the downloaded ZIP file.');
        }
    }

    private function syncMasterIngredients(string $yamlPath): void
    {
        if (! File::exists($yamlPath)) {
            Log::warning("ingredients.yaml not found at: {$yamlPath}");

            return;
        }

        $yamlContent = Yaml::parseFile($yamlPath);

        if (! is_array($yamlContent)) {
            return;
        }

        foreach ($yamlContent as $slug => $data) {
            Ingredient::updateOrCreate(
                ['slug' => $slug],
                [
                    // Wir speichern den Namen nun als Array ab, genau wie bei den Rezepten
                    'name' => [
                        'en' => $data['en'] ?? $slug,
                        'de' => $data['de'] ?? $slug,
                    ],
                    'unit' => $data['unit'] ?? '',
                    'category' => $data['category'] ?? 'misc',
                ]
            );
        }
    }

    private function parseRecipesFromDirectory(string $basePath): array
    {
        $parsedRecipes = [];
        $recipesDir = $basePath.'/recipes/recipes'; // Adjusted for storage/app/recipes/recipes

        if (! File::exists($recipesDir)) {
            return $parsedRecipes;
        }

        $bundles = File::directories($recipesDir);

        foreach ($bundles as $bundle) {
            $slug = basename($bundle);
            $metaPath = $bundle.'/meta.yaml';
            $dePath = $bundle.'/de.md';
            $enPath = $bundle.'/en.md';

            // Skip incomplete bundles
            if (! File::exists($metaPath) || ! File::exists($dePath) || ! File::exists($enPath)) {
                Log::warning("Incomplete bundle skipped: {$slug}");

                continue;
            }

            $meta = Yaml::parseFile($metaPath);
            $deDoc = YamlFrontMatter::parseFile($dePath);
            $enDoc = YamlFrontMatter::parseFile($enPath);

            $parsedRecipes[$slug] = [
                'slug' => $slug,
                'title' => [
                    'de' => $deDoc->matter('title', $slug),
                    'en' => $enDoc->matter('title', $slug),
                ],
                'instructions' => [
                    'de' => trim($deDoc->body()),
                    'en' => trim($enDoc->body()),
                ],
                'prep_time' => $meta['prep_time'] ?? 15,
                'cook_time' => $meta['cook_time'] ?? 20,
                'default_portions' => $meta['default_portions'] ?? 2,
                'categories' => $meta['categories'] ?? [],
                'ingredients' => $meta['ingredients'] ?? [],
                'image' => "recipes/images/{$slug}.webp",
                'nutrition_per_portion' => $meta['nutrition_per_portion'] ?? [],
            ];
        }

        return $parsedRecipes;
    }

    private function syncRecipesToDatabase(array $parsedRecipes): void
    {
        DB::beginTransaction();

        try {
            foreach ($parsedRecipes as $data) {
                $recipe = Recipe::updateOrCreate(
                    ['slug' => $data['slug']],
                    [
                        'title' => $data['title'],
                        'instructions' => $data['instructions'],
                        'image' => $data['image'],
                        'prep_time' => $data['prep_time'],
                        'cook_time' => $data['cook_time'],
                        'default_portions' => $data['default_portions'],
                        'categories' => $data['categories'],
                        'calories' => data_get($data, 'nutrition_per_portion.calories', 0),
                        'protein_g' => data_get($data, 'nutrition_per_portion.protein_g', 0),
                        'carbs_g' => data_get($data, 'nutrition_per_portion.carbs_g', 0),
                        'fat_g' => data_get($data, 'nutrition_per_portion.fat_g', 0),
                    ]
                );

                $syncData = [];

                if (is_array($data['ingredients'])) {
                    foreach ($data['ingredients'] as $ingData) {
                        $slug = $ingData['slug'];

                        // We check if the ingredient exists, as the GitHub Action guarantees it should
                        $ingredient = Ingredient::where('slug', $slug)->first();

                        if ($ingredient) {
                            $syncData[$ingredient->slug] = ['amount' => $ingData['amount']];
                        } else {
                            Log::warning("Ingredient slug '{$slug}' not found in database for recipe '{$data['slug']}'.");
                        }
                    }
                }

                $recipe->ingredients()->sync($syncData);
                Cache::forget("recipe_{$recipe->slug}");
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
