<?php

namespace Tests\Feature;

use App\Models\MobileApp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = $this->headersFor(User::ROLE_ADMIN);
    }

    private function headersFor(int $roleId): array
    {
        $user = User::factory()->create(['role_id' => $roleId]);

        return ['token' => $user->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function fakeApk(string $name = 'app-release.apk'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', 'manifest');
        $zip->addFromString('classes.dex', 'dex');
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.android.package-archive', null, true);
    }

    private function publish(int $code, array $extra = [], ?UploadedFile $apk = null)
    {
        return $this->post('/api/admin-apps/livmobile/releases', array_merge([
            'version_name' => "1.0.{$code}",
            'version_code' => $code,
            'changelog' => 'Notas',
            'apk' => $apk ?? $this->fakeApk(),
        ], $extra), $this->admin);
    }

    public function test_latest_without_releases_reports_code_zero(): void
    {
        $this->getJson('/api/apps/livmobile/latest?platform=android')
            ->assertOk()
            ->assertJson(['version_code' => 0, 'download_url' => null, 'force_update' => false]);
    }

    public function test_unknown_app_returns_404(): void
    {
        $this->getJson('/api/apps/nao-existe/latest')->assertNotFound();
    }

    public function test_admin_publishes_and_app_downloads_without_login(): void
    {
        $this->publish(2)->assertCreated()->assertJsonPath('release.version_code', 2);

        Storage::disk('local')->assertExists('apps/livmobile/2.apk');

        $latest = $this->getJson('/api/apps/livmobile/latest?platform=android')
            ->assertOk()
            ->assertJson(['version_code' => 2, 'version_name' => '1.0.2', 'changelog' => 'Notas', 'force_update' => false])
            ->json();

        $this->assertStringEndsWith('/api/apps/livmobile/download/2.apk', $latest['download_url']);

        $download = $this->get('/api/apps/livmobile/download/2.apk');
        $download->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $download->headers->get('Content-Type'));
    }

    public function test_version_code_must_increase(): void
    {
        $this->publish(5)->assertCreated();

        $this->publish(5)->assertStatus(422)->assertJsonValidationErrors('version_code');
        $this->publish(4)->assertStatus(422)->assertJsonValidationErrors('version_code');

        $this->getJson('/api/apps/livmobile/latest')->assertJson(['version_code' => 5]);
    }

    public function test_forced_release_sets_minimum_and_next_optional_release_keeps_it(): void
    {
        $this->publish(3, ['force_update' => 1])->assertCreated();
        $this->getJson('/api/apps/livmobile/latest')->assertJson(['force_update' => true, 'min_version_code' => 3]);

        $this->publish(4, ['force_update' => 0])->assertCreated();
        $this->getJson('/api/apps/livmobile/latest')->assertJson([
            'version_code' => 4,
            'force_update' => false,
            'min_version_code' => 3,
        ]);
    }

    public function test_rejects_files_that_are_not_apks(): void
    {
        $text = UploadedFile::fake()->createWithContent('app.apk', 'isto não é um zip');
        $this->publish(2, [], $text)->assertStatus(422)->assertJsonValidationErrors('apk');

        $wrongExtension = $this->fakeApk('app.zip');
        $this->publish(2, [], $wrongExtension)->assertStatus(422)->assertJsonValidationErrors('apk');

        $this->assertSame(0, MobileApp::where('slug', 'livmobile')->first()->releases()->count());
    }

    public function test_policy_update_cannot_exceed_latest_release(): void
    {
        $this->publish(6)->assertCreated();

        $this->postJson('/api/admin-apps/livmobile', ['min_version_code' => 7, 'force_update' => true], $this->admin)
            ->assertStatus(422);

        $this->postJson('/api/admin-apps/livmobile', ['min_version_code' => 6, 'force_update' => true], $this->admin)
            ->assertOk()
            ->assertJsonPath('app.min_version_code', 6);
    }

    public function test_only_admin_can_publish(): void
    {
        $supervisor = $this->headersFor(User::ROLE_SUPERVISOR);

        $this->getJson('/api/admin-apps', $supervisor)->assertForbidden();
        $this->post('/api/admin-apps/livmobile/releases', [
            'version_name' => '1.0.2', 'version_code' => 2, 'apk' => $this->fakeApk(),
        ], $supervisor)->assertForbidden();
    }
}
