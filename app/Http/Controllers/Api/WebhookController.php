<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use ZipArchive;

class WebhookController extends Controller
{
    #[OA\Post(
        path: '/webhooks/github',
        summary: 'Synchronize recipes from GitHub',
        tags: ['System']
    )]
    #[OA\Parameter(
        name: 'X-Hub-Signature-256',
        in: 'header',
        required: false,
        description: 'HMAC hex digest of the payload',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'Successful synchronization')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function handle(Request $request)
    {
        try {
            $recipePath = storage_path('app/recipes');

            $this->downloadAndExtractRepo($recipePath);
            $parsedRecipes = $this->parseRecipesFromDirectory($recipePath);

            return response()->json([
                'status' => 'success',
                'message' => 'Synced '.count($parsedRecipes).' recipes.',
            ]);

        } catch (\Exception $e) {
            Log::error('GitHub Sync Error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync recipes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function downloadAndExtractRepo(string $destinationPath): void
    {
        $repo = env('GITHUB_REPO');
        $branch = env('GITHUB_BRANCH', 'main');
        $token = env('GITHUB_TOKEN');

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

                File::cleanDirectory($destinationPath);

                $files = File::allFiles($repoRoot);
                foreach ($files as $file) {
                    if ($file->getExtension() === 'md') {
                        File::copy($file->getPathname(), $destinationPath.'/'.$file->getFilename());
                    }
                }
            }

            File::delete($zipPath);
            File::deleteDirectory($tempExtractPath);
        } else {
            throw new \Exception('Failed to open the downloaded ZIP file.');
        }
    }

    private function parseRecipesFromDirectory(string $directoryPath): array
    {
        $parsedRecipes = [];

        if (! File::exists($directoryPath)) {
            Log::warning("Recipe directory not found: {$directoryPath}");

            return [];
        }

        $files = File::files($directoryPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'md') {
                $document = YamlFrontMatter::parseFile($file->getPathname());
                $yamlData = $document->matter();
                $parsedRecipes[] = $yamlData;
            }
        }

        return $parsedRecipes;
    }
}
