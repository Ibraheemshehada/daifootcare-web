<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WoundScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The probe's whole promise is that it writes nothing.
 *
 * That promise is invisible: a probe that quietly created a wound scan would
 * look identical on screen and would only show up later as a patient with a
 * measurement nobody took — inside the clinical study's own numbers. So it is
 * asserted here rather than trusted.
 */
class AnalysisProbeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real JPEG, byte for byte, rather than UploadedFile::fake()->image().
     *
     * The fake needs the GD extension to synthesise a picture, and GD is not
     * installed on this machine — so every test that touched an image failed
     * before it reached the code under test. These 631 bytes are a 1x1 JPEG and
     * need nothing but the filesystem.
     */
    private function jpeg(string $name = 'wound.jpg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'probe').'.jpg';
        file_put_contents($path, base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U'
            .'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBARE'
            .'A/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQ'
            .'EAAD8AKp//2Q=='
        ));

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function fakeSidecar(): void
    {
        Http::fake([
            '*/analyse' => Http::response([
                'length' => 2.79,
                'width' => 1.96,
                'area' => 4.29,
                'is_calibrated' => true,
                'pixels_per_cm' => 152.3,
                'tilt_deg' => 10.3,
                'tissue_findings' => [
                    ['type' => 'granulation', 'probability' => 0.91, 'threshold' => 0.5, 'is_present' => true],
                    ['type' => 'slough', 'probability' => 0.44, 'threshold' => 0.5, 'is_present' => false],
                ],
                'infection' => 'Not Present',
                'ischaemia' => 'Adequate',
                'overlay_jpeg_b64' => base64_encode('not-really-a-jpeg'),
            ]),
        ]);
    }

    public function test_an_admin_gets_the_analysis_back(): void
    {
        $this->fakeSidecar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->postJson('/api/v1/analysis/probe', [
            'image' => $this->jpeg(),
        ]);

        $response->assertOk()
            ->assertJsonPath('stored', false)
            ->assertJsonPath('analysis.pixels_per_cm', 152.3)
            // The mask matters as much as the numbers: it is what says whether
            // the numbers are worth reading at all.
            ->assertJsonStructure(['analysis' => ['tissue_findings', 'overlay_jpeg_b64']]);
    }

    public function test_nothing_is_written(): void
    {
        $this->fakeSidecar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson('/api/v1/analysis/probe', [
            'image' => $this->jpeg(),
        ])->assertOk();

        // No scan, and no patient to hang one on. If either of these ever fails,
        // a test image has become clinical data.
        $this->assertSame(0, WoundScan::count());
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_a_clinician_cannot_reach_it(): void
    {
        $this->fakeSidecar();
        $doctor = User::factory()->create(['role' => User::ROLE_DOCTOR]);

        $this->actingAs($doctor)->postJson('/api/v1/analysis/probe', [
            'image' => $this->jpeg(),
        ])->assertForbidden();
    }

    public function test_a_non_image_is_refused(): void
    {
        $this->fakeSidecar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson('/api/v1/analysis/probe', [
            'image' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_sidecar_outage_is_reported_not_swallowed(): void
    {
        // A silent empty result would read as "this photograph has no wound",
        // which is the one wrong answer that looks like a real one.
        Http::fake(['*/analyse' => Http::response('', 500)]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson('/api/v1/analysis/probe', [
            'image' => $this->jpeg(),
        ])->assertStatus(422);
    }
}
