<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Panorama;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PanoramaFixture;
use Tests\TestCase;

/**
 * php artisan panoramas:import: 360° photos from a folder become hidden photo points, placed by their EXIF GPS, with
 * a web copy and a thumbnail (no EXIF) on the public disk. Photos already imported are left as the admin left them.
 */
class PanoramaImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Panorama::DISK);
        Building::factory()->campus()->create();
    }

    private function folder(): string
    {
        $directory = storage_path('framework/testing/panoramas-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        return $directory;
    }

    public function test_photos_are_added_hidden_at_their_gps_position_with_codes_from_their_file_names(): void
    {
        $folder = $this->folder();
        PanoramaFixture::file($folder, 'P3.JPG', gps: [8.541122, 124.7483552]);
        PanoramaFixture::file($folder, 'p1.jpg');

        $this->artisan('panoramas:import', ['directory' => $folder])
            ->expectsOutput('Added: pn1, pn3.')
            ->assertSuccessful();

        $photo = Panorama::query()->where('code', 'pn3')->sole();
        $this->assertSame('Photo point 3', $photo->name);
        $this->assertEqualsWithDelta(8.541122, $photo->latitude, 0.000001);
        $this->assertEqualsWithDelta(124.7483552, $photo->longitude, 0.000001);
        $this->assertSame(1, $photo->floor);
        $this->assertNull($photo->north_offset);
        $this->assertFalse($photo->is_visible);
        $this->assertSame(2, Panorama::count());
    }

    public function test_the_copies_are_resized_and_carry_no_exif(): void
    {
        $folder = $this->folder();
        PanoramaFixture::file($folder, 'p5.jpg', 5000, 2500);

        $this->artisan('panoramas:import', ['directory' => $folder])->assertSuccessful();

        $photo = Panorama::query()->where('code', 'pn5')->sole();
        $disk = Storage::disk(Panorama::DISK);
        $this->assertSame([4096, 2048], array_slice(getimagesize($disk->path($photo->image_path)), 0, 2));
        $this->assertSame([640, 320], array_slice(getimagesize($disk->path($photo->thumb_path)), 0, 2));
        foreach ([$photo->image_path, $photo->thumb_path] as $path) {
            $this->assertStringNotContainsString("Exif\0\0", $disk->get($path), $path);
            $this->assertArrayNotHasKey('GPSLatitude', @exif_read_data($disk->path($path)) ?: [], $path);
        }
        $this->assertStringStartsWith('/storage/panoramas/pn5-', $photo->imageUrl());
    }

    public function test_a_smaller_photo_is_not_enlarged(): void
    {
        $folder = $this->folder();
        PanoramaFixture::file($folder, 'p2.jpg', 2048, 1024);

        $this->artisan('panoramas:import', ['directory' => $folder])->assertSuccessful();

        $photo = Panorama::query()->where('code', 'pn2')->sole();
        $this->assertSame([2048, 1024], array_slice(getimagesize(Storage::disk(Panorama::DISK)->path($photo->image_path)), 0, 2));
    }

    public function test_importing_again_keeps_what_the_admin_changed(): void
    {
        $folder = $this->folder();
        PanoramaFixture::file($folder, 'p1.jpg');
        $this->artisan('panoramas:import', ['directory' => $folder])->assertSuccessful();
        $photo = Panorama::query()->where('code', 'pn1')->sole();
        $photo->update(['name' => 'Entrance', 'latitude' => 8.5413, 'longitude' => 124.7483, 'north_offset' => 120, 'is_visible' => true]);
        $files = Storage::disk(Panorama::DISK)->allFiles();

        $this->artisan('panoramas:import', ['directory' => $folder])
            ->expectsOutput('Added: none.')
            ->expectsOutput('Skipped p1.jpg: pn1 is already imported (kept as it is).')
            ->assertSuccessful();

        $photo->refresh();
        $this->assertSame(['Entrance', 8.5413, 124.7483, 120.0, true], [$photo->name, $photo->latitude, $photo->longitude, $photo->north_offset, $photo->is_visible]);
        $this->assertSame($files, Storage::disk(Panorama::DISK)->allFiles());
    }

    public function test_unusable_files_are_skipped_with_the_reason_and_the_rest_are_imported(): void
    {
        $folder = $this->folder();
        PanoramaFixture::file($folder, 'p1.jpg');
        PanoramaFixture::file($folder, 'p2.jpg', gps: null);
        PanoramaFixture::file($folder, 'p3.jpg', 2048, 1536);
        PanoramaFixture::file($folder, 'p4.jpg', gps: [14.5995, 120.9842]);
        PanoramaFixture::file($folder, 'gate.jpg');
        file_put_contents($folder.'/p6.jpg', 'not a photo');

        $this->artisan('panoramas:import', ['directory' => $folder])
            ->expectsOutput('Added: pn1.')
            ->expectsOutput('Skipped p2.jpg: it has no GPS position (upload it in the admin page and place it on the map).')
            ->expectsOutput('Skipped p3.jpg: it is 2048×1536, not a full 360° photo (twice as wide as it is tall).')
            ->expectsOutput('Skipped p4.jpg: its GPS position (14.5995, 120.9842) is not on campus.')
            ->expectsOutput('Skipped p6.jpg: it is not a JPEG photo.')
            ->expectsOutput('Skipped gate.jpg: there is no number in the file name to make its code from.')
            ->assertSuccessful();

        $this->assertSame(['pn1'], Panorama::query()->pluck('code')->all());
        $this->assertCount(2, Storage::disk(Panorama::DISK)->allFiles());
    }

    public function test_a_missing_folder_is_reported(): void
    {
        $this->artisan('panoramas:import', ['directory' => storage_path('framework/testing/no-such-folder')])
            ->expectsOutputToContain('There is no folder at')
            ->assertFailed();
    }
}
