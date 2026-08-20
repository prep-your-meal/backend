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
use Illuminate\Support\Str;
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

            $this->downloadAndExtractRepo($storageBasePath);
            $ingredientLookup = $this->syncMasterIngredients($storageBasePath.'/ingredients.yaml');
            $parsedRecipes = $this->parseRecipesFromDirectory($storageBasePath);
            $this->syncRecipesToDatabase($parsedRecipes, $ingredientLookup);

            return response()->json([
                'status' => 'success',
                'message' => 'Successfully synced '.count($parsedRecipes).' recipes and master ingredients to the database.',
            ]);

            // Throwable catches EVERYTHING, including fatal PHP 8 TypeErrors
        } catch (\Throwable $e) {
            Log::error('GitHub Sync Error: '.$e->getMessage());

            // Safely parse the status code (database errors often have string codes like '42S22')
            $code = $e->getCode();
            $statusCode = (is_numeric($code) && $code >= 400 && $code < 600) ? (int) $code : 500;

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync recipes.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(), // Helps with debugging
                'line' => $e->getLine(),
            ], $statusCode);
        }
    }

    /**
     * Verify the custom sync token sent by the GitHub Action.
     */
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

    /**
     * Download the repository ZIP archive from GitHub and extract required files.
     */
    private function downloadAndExtractRepo(string $destinationPath): void
    {
        $repo = trim(config('services.github.repo'));
        $branch = trim(config('services.github.branch', 'main'));
        $token = trim(config('services.github.token'));

        $url = "https://api.github.com/repos/{$repo}/zipball/{$branch}";
        $zipPath = storage_path('app/temp_repo.zip');
        $tempExtractPath = storage_path('app/temp_extracted');

        $response = Http::withToken($token)->get($url);

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

                // 1. Copy ingredients.yaml
                $yamlSource = $repoRoot.'/ingredients.yaml';
                if (File::exists($yamlSource)) {
                    File::copy($yamlSource, $destinationPath.'/ingredients.yaml');
                }

                // 2. Copy markdown recipes
                $recipesSource = $repoRoot.'/recipes';
                if (File::exists($recipesSource)) {
                    File::ensureDirectoryExists($destinationPath.'/recipes');
                    File::copyDirectory($recipesSource, $destinationPath.'/recipes');
                }

                // 3. Copy images directly to the public folder
                $imagesSource = $repoRoot.'/recipes/images';
                if (File::exists($imagesSource)) {
                    $publicImagesPath = public_path('recipes/images');
                    File::ensureDirectoryExists($publicImagesPath);
                    File::copyDirectory($imagesSource, $publicImagesPath);
                }
            }

            File::delete($zipPath);
            File::deleteDirectory($tempExtractPath);
        } else {
            throw new \Exception('Failed to open the downloaded ZIP file.');
        }
    }

    /**
     * Parse master ingredients from ingredients.yaml and build a lookup map.
     */
    private function syncMasterIngredients(string $yamlPath): array
    {
        $ingredientLookup = [];

        if (! File::exists($yamlPath)) {
            Log::warning("ingredients.yaml not found at: {$yamlPath}");

            return $ingredientLookup;
        }

        $yamlContent = Yaml::parseFile($yamlPath);

        if (! is_array($yamlContent)) {
            return $ingredientLookup;
        }

        foreach ($yamlContent as $key => $data) {
            // Update or create the master ingredient using the YAML key as the unique slug
            $ingredient = Ingredient::updateOrCreate(
                ['slug' => $key],
                [
                    'name' => $data['en'] ?? ($data['de'] ?? $key),
                    'unit' => $data['unit'] ?? '',
                    'category' => $data['category'] ?? 'misc',
                ]
            );

            // Build lookup references for both German and English names to match recipe entries
            if (! empty($data['de'])) {
                $ingredientLookup[mb_strtolower(trim($data['de']))] = $ingredient;
            }
            if (! empty($data['en'])) {
                $ingredientLookup[mb_strtolower(trim($data['en']))] = $ingredient;
            }
            $ingredientLookup[mb_strtolower($key)] = $ingredient;
        }

        return $ingredientLookup;
    }

    /**
     * Parse Markdown recipe files and group them by filename (canonical slug)
     * to support multiple languages seamlessly.
     */
    private function parseRecipesFromDirectory(string $basePath): array
    {
        $groupedRecipes = [];
        $languages = ['de', 'en'];

        foreach ($languages as $lang) {
            $langDir = $basePath.'/recipes/'.$lang;

            if (! File::exists($langDir)) {
                continue;
            }

            $files = File::files($langDir);

            foreach ($files as $file) {
                if ($file->getExtension() === 'md') {
                    $document = YamlFrontMatter::parseFile($file->getPathname());
                    $yamlData = $document->matter();

                    $canonicalSlug = $file->getBasename('.md');
                    $yamlData['slug'] = $canonicalSlug;

                    if (! isset($groupedRecipes[$canonicalSlug])) {
                        $groupedRecipes[$canonicalSlug] = $yamlData;
                        $groupedRecipes[$canonicalSlug]['title'] = [];
                        $groupedRecipes[$canonicalSlug]['instructions'] = [];
                    }

                    $groupedRecipes[$canonicalSlug]['title'][$lang] = $yamlData['title'];

                    $groupedRecipes[$canonicalSlug]['instructions'][$lang] = trim($document->body());
                }
            }
        }

        return $groupedRecipes;
    }

    /**
     * Takes the parsed YAML data and syncs recipes and their ingredient relationships with the database.
     */
    private function syncRecipesToDatabase(array $parsedRecipes, array $ingredientLookup): void
    {
        DB::beginTransaction();

        try {
            foreach ($parsedRecipes as $yamlData) {
                $recipe = Recipe::updateOrCreate(
                    ['slug' => $yamlData['slug']],
                    [
                        'title' => $yamlData['title'],
                        'instructions' => $yamlData['instructions'] ?? null,
                        'image' => $yamlData['image'] ?? null,
                        'prep_time' => $yamlData['prep_time'] ?? null,
                        'cook_time' => $yamlData['cook_time'] ?? null,
                        'default_portions' => $yamlData['default_portions'] ?? 1,
                        'categories' => $yamlData['categories'] ?? null,

                        // Extract macros safely using data_get (prevents undefined array key crashes)
                        'calories' => data_get($yamlData, 'nutrition_per_portion.calories', 0),
                        'protein_g' => data_get($yamlData, 'nutrition_per_portion.protein_g', 0),
                        'carbs_g' => data_get($yamlData, 'nutrition_per_portion.carbs_g', 0),
                        'fat_g' => data_get($yamlData, 'nutrition_per_portion.fat_g', 0),
                    ]
                );

                $syncData = [];

                if (isset($yamlData['ingredients']) && is_array($yamlData['ingredients'])) {
                    foreach ($yamlData['ingredients'] as $ingData) {
                        $ingredientNameLower = mb_strtolower(trim($ingData['name']));

                        if (isset($ingredientLookup[$ingredientNameLower])) {
                            $masterIngredient = $ingredientLookup[$ingredientNameLower];
                            $syncData[$masterIngredient->slug] = ['amount' => $ingData['amount']];
                        } else {
                            $fallbackSlug = Str::slug($ingData['name']);
                            $newIngredient = Ingredient::updateOrCreate(
                                ['slug' => $fallbackSlug],
                                [
                                    'name' => $ingData['name'],
                                    'unit' => $ingData['unit'] ?? '',
                                    'category' => $ingData['category'] ?? 'misc',
                                ]
                            );
                            $syncData[$fallbackSlug] = ['amount' => $ingData['amount']];
                        }
                    }
                }

                $recipe->ingredients()->sync($syncData);

                // Cache-Busting! Clear the old cache for this specific recipe to serve fresh data.
                Cache::forget("recipe_{$recipe->slug}");
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
