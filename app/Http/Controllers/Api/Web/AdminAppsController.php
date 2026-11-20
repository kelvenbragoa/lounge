<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\MobileApp;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminAppsController extends Controller
{
    private const MAX_APK_KB = 153600;

    public function index()
    {
        $apps = MobileApp::with('releases.publisher', 'releases.app')->orderBy('id')->get();

        return response()->json([
            'apps' => $apps->map(fn (MobileApp $app) => array_merge($app->toAdminArray(), [
                'releases' => $app->releases->map->toApiArray()->values(),
            ])),
        ]);
    }

    public function update(Request $request, string $slug)
    {
        $app = MobileApp::where('slug', $slug)->firstOrFail();
        $latestCode = (int) $app->releases()->max('version_code');

        $data = $request->validate([
            'min_version_code' => ['required', 'integer', 'min:0', 'max:'.$latestCode],
            'force_update' => ['required', 'boolean'],
        ], [
            'min_version_code.max' => 'O mínimo obrigatório não pode ser maior do que a última versão publicada ('.$latestCode.').',
        ]);

        $app->update($data);

        return response()->json([
            'message' => 'Política de actualização guardada.',
            'app' => $app->fresh()->toAdminArray(),
        ]);
    }

    public function storeRelease(Request $request, string $slug)
    {
        $app = MobileApp::where('slug', $slug)->firstOrFail();
        $platform = 'android';
        $latestCode = (int) $app->releases()->where('platform', $platform)->max('version_code');

        $data = $request->validate([
            'version_name' => ['required', 'string', 'max:40'],
            'version_code' => ['required', 'integer', 'gt:'.$latestCode],
            'changelog' => ['nullable', 'string', 'max:2000'],
            'force_update' => ['nullable', 'boolean'],
            'min_version_code' => ['nullable', 'integer', 'min:0'],
            'apk' => ['required', 'file', 'max:'.self::MAX_APK_KB],
        ], [
            'version_code.gt' => 'O version code tem de ser maior do que '.$latestCode.' (última versão publicada).',
            'apk.required' => 'Seleccione o ficheiro APK.',
            'apk.max' => 'O APK não pode ter mais de 150 MB.',
            'apk.uploaded' => 'O envio do APK falhou. Verifique o limite de upload do servidor.',
        ]);

        $file = $request->file('apk');
        $this->assertIsApk($file);

        $versionCode = (int) $data['version_code'];
        $forceUpdate = $request->boolean('force_update');

        if ($request->filled('min_version_code') && (int) $data['min_version_code'] > $versionCode) {
            throw ValidationException::withMessages([
                'min_version_code' => 'O mínimo obrigatório não pode ser maior do que o version code publicado.',
            ]);
        }

        $filePath = $file->storeAs("apps/{$app->slug}", "{$versionCode}.apk", AppRelease::DISK);

        try {
            $release = DB::transaction(function () use ($app, $data, $platform, $versionCode, $forceUpdate, $filePath, $file, $request) {
                $release = $app->releases()->create([
                    'platform' => $platform,
                    'version_name' => $data['version_name'],
                    'version_code' => $versionCode,
                    'file_path' => $filePath,
                    'file_size' => $file->getSize(),
                    'changelog' => $data['changelog'] ?? null,
                    'force_update' => $forceUpdate,
                    'published_by' => Auth::id(),
                ]);

                $app->force_update = $forceUpdate;
                if ($request->filled('min_version_code')) {
                    $app->min_version_code = (int) $data['min_version_code'];
                } elseif ($forceUpdate) {
                    $app->min_version_code = $versionCode;
                }
                $app->save();

                return $release;
            });
        } catch (Throwable $e) {
            Storage::disk(AppRelease::DISK)->delete($filePath);
            throw $e;
        }

        return response()->json([
            'message' => 'Versão '.$release->version_name.' ('.$release->version_code.') publicada.',
            'app' => $app->fresh()->toAdminArray(),
            'release' => $release->load('app', 'publisher')->toApiArray(),
        ], 201);
    }

    /**
     * Um APK é um ZIP com AndroidManifest.xml; a extensão sozinha não chega.
     */
    private function assertIsApk(UploadedFile $file): void
    {
        $fail = fn () => throw ValidationException::withMessages(['apk' => 'O ficheiro tem de ser um APK Android válido.']);

        if (strtolower($file->getClientOriginalExtension()) !== 'apk') {
            $fail();
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($file->getRealPath()) !== true) {
                $fail();
            }
            $hasManifest = $zip->locateName('AndroidManifest.xml') !== false;
            $zip->close();
            if (! $hasManifest) {
                $fail();
            }

            return;
        }

        $handle = fopen($file->getRealPath(), 'rb');
        $magic = $handle ? fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== "PK\x03\x04") {
            $fail();
        }
    }
}
