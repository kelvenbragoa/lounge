<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\MobileApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Rotas públicas (sem login): a app consulta antes do ecrã de entrada, para uma
 * versão antiga poder actualizar mesmo com a sessão expirada.
 */
class AppUpdateController extends Controller
{
    public function latest(Request $request, string $slug)
    {
        $app = MobileApp::where('slug', $slug)->firstOrFail();
        $platform = $request->query('platform', 'android');
        $release = $app->latestRelease($platform);

        return response()->json([
            'slug' => $app->slug,
            'name' => $app->name,
            'package_name' => $app->package_name,
            'platform' => $platform,
            'version_name' => $release?->version_name,
            'version_code' => $release?->version_code ?? 0,
            'min_version_code' => (int) $app->min_version_code,
            'force_update' => (bool) $app->force_update,
            'changelog' => $release?->changelog,
            'file_size' => $release?->file_size,
            'download_url' => $release?->downloadUrl(),
        ]);
    }

    public function download(string $slug, int $versionCode)
    {
        $release = AppRelease::whereHas('app', fn ($q) => $q->where('slug', $slug))
            ->where('version_code', $versionCode)
            ->firstOrFail();

        $disk = Storage::disk(AppRelease::DISK);
        abort_unless($disk->exists($release->file_path), 404, 'Ficheiro da versão não encontrado.');

        return response()->download(
            $disk->path($release->file_path),
            "{$slug}-{$release->version_name}-{$release->version_code}.apk",
            ['Content-Type' => 'application/vnd.android.package-archive']
        );
    }
}
