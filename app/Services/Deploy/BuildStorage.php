<?php

namespace App\Services\Deploy;

use App\Enums\BuildSource;
use App\Exceptions\DeployException;
use App\Models\Build;
use App\Models\Project;
use FilesystemIterator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * Saved front-end builds: archives with the contents of the build folder
 * (public/build: manifest.json + assets/), stored as .tar.gz so the server
 * only needs "tar" to unpack them. Only the newest N per project are kept.
 */
class BuildStorage
{
    /**
     * Saves an uploaded .zip / .tar.gz. The archive may contain the build files at
     * its root, or a build/ or public/build/ folder (it is found by manifest.json).
     *
     * @param  array{label?: string|null, commit_hash?: string|null, branch?: string|null, user_id?: int|null}  $meta
     */
    public function storeUpload(Project $project, UploadedFile $file, array $meta = []): Build
    {
        $work = $this->tempDir();

        try {
            $this->extract((string) $file->getRealPath(), $file->getClientOriginalName(), $work);

            return $this->storeDirectory($project, $this->findBuildRoot($work), BuildSource::Upload, [
                ...$meta,
                'original_name' => $file->getClientOriginalName(),
            ]);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Packs a build folder (e.g. C:\projects\app\public\build) and saves it.
     *
     * @param  array{label?: string|null, original_name?: string|null, commit_hash?: string|null, branch?: string|null, user_id?: int|null}  $meta
     */
    public function storeDirectory(Project $project, string $directory, BuildSource $source, array $meta = []): Build
    {
        if (! is_dir($directory)) {
            throw new DeployException(__('Build folder not found: :path', ['path' => $directory]));
        }

        $relative = 'builds/'.$project->id.'/'.Str::lower((string) Str::ulid()).'.tar.gz';
        $target = Storage::disk('local')->path($relative);
        File::ensureDirectoryExists(dirname($target));

        $files = $this->pack($directory, $target);

        if ($files === 0) {
            @unlink($target);

            throw new DeployException(__('The build folder is empty: :path', ['path' => $directory]));
        }

        $build = $project->builds()->create([
            'user_id' => $meta['user_id'] ?? null,
            'source' => $source,
            'label' => $meta['label'] ?? null,
            'original_name' => $meta['original_name'] ?? null,
            'path' => $relative,
            'size' => (int) filesize($target),
            'file_count' => $files,
            'has_manifest' => is_file($directory.DIRECTORY_SEPARATOR.'manifest.json'),
            'commit_hash' => $meta['commit_hash'] ?? null,
            'branch' => $meta['branch'] ?? null,
        ]);

        $this->prune($project);

        return $build;
    }

    /** Deletes the oldest builds above the project's limit. */
    public function prune(Project $project): void
    {
        $project->builds()
            ->offset(max(1, $project->keep_builds))
            ->limit(1000)
            ->get()
            ->each(fn (Build $build) => $this->delete($build));
    }

    public function delete(Build $build): void
    {
        Storage::disk('local')->delete($build->path);
        $build->delete();
    }

    /** Packs a folder into .tar.gz and returns the number of files. */
    private function pack(string $directory, string $target): int
    {
        $tar = substr($target, 0, -3); // "….tar"

        $files = 0;
        foreach (new RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile()) {
                $files++;
            }
        }

        if ($files === 0) {
            return 0;
        }

        $archive = new PharData($tar);
        $archive->buildFromDirectory($directory);
        $archive->compress(Phar::GZ);
        unset($archive);

        Phar::unlinkArchive($tar);

        return $files;
    }

    private function extract(string $archive, string $originalName, string $directory): void
    {
        $name = strtolower($originalName);

        if (str_ends_with($name, '.zip')) {
            $zip = new ZipArchive;

            if ($zip->open($archive) !== true) {
                throw new DeployException(__('The .zip file could not be opened.'));
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->assertSafeEntry((string) $zip->getNameIndex($i));
            }

            $zip->extractTo($directory);
            $zip->close();

            return;
        }

        if (! str_ends_with($name, '.tar.gz') && ! str_ends_with($name, '.tgz') && ! str_ends_with($name, '.tar')) {
            throw new DeployException(__('Upload a .zip, .tar.gz or .tgz archive.'));
        }

        // PharData picks the format from the file extension; the PHP upload temp file has none.
        $copy = $directory.DIRECTORY_SEPARATOR.'upload'.(str_ends_with($name, '.tar') ? '.tar' : '.tar.gz');
        copy($archive, $copy);

        try {
            $phar = new PharData($copy);
            $prefix = 'phar://'.str_replace('\\', '/', $copy).'/';

            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                $this->assertSafeEntry(Str::after(str_replace('\\', '/', $entry->getPathname()), $prefix));
            }

            $phar->extractTo($directory.DIRECTORY_SEPARATOR.'files', null, true);
            unset($phar);
        } catch (DeployException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DeployException(__('The archive could not be unpacked: :error', ['error' => $e->getMessage()]), previous: $e);
        } finally {
            Phar::unlinkArchive($copy);
        }

        // Keep only the unpacked files in the work folder.
        foreach (File::allFiles($directory.DIRECTORY_SEPARATOR.'files', true) as $file) {
            $destination = $directory.DIRECTORY_SEPARATOR.$file->getRelativePathname();
            File::ensureDirectoryExists(dirname($destination));
            File::move($file->getPathname(), $destination);
        }

        File::deleteDirectory($directory.DIRECTORY_SEPARATOR.'files');
    }

    /** Blocks "zip slip" entries that would write outside the folder. */
    private function assertSafeEntry(string $entry): void
    {
        $entry = str_replace('\\', '/', $entry);

        if (str_starts_with($entry, '/') || preg_match('~(^|/)\.\.(/|$)~', $entry) || preg_match('~^[A-Za-z]:~', $entry)) {
            throw new DeployException(__('The archive contains an unsafe path: :path', ['path' => $entry]));
        }
    }

    /** The folder with manifest.json: the root, build/, public/build/ or a single top folder. */
    private function findBuildRoot(string $directory): string
    {
        $candidates = [$directory, $directory.'/build', $directory.'/public/build'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate.'/manifest.json')) {
                return $candidate;
            }
        }

        // A zip made by right-click → "Send to compressed folder" on the build folder.
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));

        if (count($entries) === 1 && is_dir($directory.'/'.$entries[0])) {
            return $this->findBuildRoot($directory.'/'.$entries[0]);
        }

        return $directory;
    }

    private function tempDir(): string
    {
        $path = storage_path('app/private/tmp/'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($path);

        return $path;
    }
}
