<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Modules\Social\Infrastructure\StorageUsage;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\UploadLimits;
use App\Support\HrisFormat;
use Closure;
use finfo;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Photos and files on posts, comments and messages, plus the photos of
 * groups and group chats: checked, stored privately, and cleaned up.
 *
 * What a file is gets read from its own bytes, never from its name or what
 * the browser claimed. Photos are the four image types a browser can show,
 * and SVG is refused outright, since it can carry script. Files are everyday
 * office formats only (PDF, Word, Excel, PowerPoint, text, CSV), so nothing
 * that runs can be passed around.
 *
 * A photo bigger than 2048 px on its long side is shrunk here too, not just
 * in the browser, so every photo on the disk stays a sensible size however
 * it arrived. GIFs are left alone, so they keep moving.
 *
 * Everything goes on the private `local` disk through the same
 * AttachmentStore as employee documents and task files, under the same
 * per-file cap, and is only ever read back through a permission-checked
 * route. The storage budget is Community and Chat's own
 * (`copems.uploads.social_storage_ceiling_bytes`): their files never use up
 * the room HR documents and task evidence need, and those never use up
 * theirs.
 *
 * A photo is served inline so the feed can show it, and a file always as a
 * download — the one exception to the uploads guide's download-only rule,
 * kept safe by the verified image types, nosniff and the refused SVG. Both
 * carry the guide's private five-minute cache.
 */
final class Attachments
{
    /** @var list<string> */
    public const array PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** @var array<string, string> type => what people call it */
    public const array FILE_TYPES = [
        'application/pdf' => 'PDF',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint',
        'text/plain' => 'Text',
        'text/csv' => 'CSV',
    ];

    /** @var list<string> What the file button offers, in the same order. */
    public const array FILE_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv'];

    /** What a full Community and Chat budget says, at the pick-time check and under the lock alike. */
    public const string FULL = 'Community and Chat storage is full. Delete old photos and files before uploading more.';

    /** The longest side a stored photo keeps. */
    public const int PHOTO_MAX_EDGE = 2048;

    /** Group and group chat photos are small squares. */
    public const int AVATAR_EDGE = 256;

    public const string REFUSED = 'Only photos (JPEG, PNG, GIF, WebP) and PDF, Word, Excel, PowerPoint, text or CSV files can be added.';

    /** Office files are zips inside. The part that says which one it is. */
    private const array OFFICE_PARTS = [
        'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    /** Past this many pixels a photo is too big to redraw safely on a small host. */
    private const int MAX_PIXELS = 40_000_000;

    private function __construct() {}

    /**
     * What a file really is: [PHOTO or FILE, its type], or null when it's
     * neither. Read from the bytes. The name only breaks a tie for office
     * files, which some systems report as a plain zip.
     *
     * @return array{string, string}|null
     */
    public static function identify(UploadedFile $file): ?array
    {
        $path = (string) $file->getRealPath();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (in_array($mime, self::PHOTO_TYPES, true)) {
            return @getimagesize($path) === false ? null : ['PHOTO', $mime];
        }

        if ($mime === 'application/pdf') {
            return ['FILE', $mime];
        }

        if (isset(self::FILE_TYPES[$mime]) && str_starts_with($mime, 'application/vnd.openxmlformats')) {
            return ['FILE', $mime];
        }

        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && isset(self::OFFICE_PARTS[$extension])) {
            return self::officeType($path, $extension);
        }

        // Plain text reads as text/plain whatever it holds, so the name
        // decides between a note and a spreadsheet export.
        if (in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true) && in_array($extension, ['txt', 'csv'], true)) {
            return ['FILE', $extension === 'csv' ? 'text/csv' : 'text/plain'];
        }

        return null;
    }

    /**
     * Every file is checked before any is stored, so a bad tenth file never
     * leaves nine orphans behind.
     *
     * @param  list<UploadedFile>  $files
     */
    public static function check(array $files): void
    {
        if (count($files) > SocialRules::MAX_ATTACHMENTS) {
            throw new RuntimeException('Up to '.SocialRules::MAX_ATTACHMENTS.' photos and files at a time.');
        }

        $cap = self::maxFileBytes();
        $total = 0;

        foreach ($files as $file) {
            $size = (int) $file->getSize();

            if (self::identify($file) === null) {
                throw new RuntimeException(self::REFUSED);
            }

            if ($size > $cap) {
                throw new RuntimeException('That file is '.HrisFormat::bytes($size).'. The limit is '.HrisFormat::bytes($cap).'.');
            }

            $total += $size;
        }

        if ($files !== [] && self::usedBytes() + $total > self::storageCeilingBytes()) {
            throw new RuntimeException(self::FULL);
        }
    }

    /**
     * Stores one photo or file under `{area}-photos/` or `{area}-files/`, and
     * records it through `$persist` inside the budget lock, against
     * Community and Chat's own budget. The path
     * is added to `$stored` so a caller whose later step fails can discard
     * everything it wrote.
     *
     * @template T
     *
     * @param  string  $area  `community` or `chat`
     * @param  Closure(array{path: string, kind: string, mime: string, size: int, name: string|null}): T  $persist
     * @param  list<string>  $stored
     * @return T
     */
    public static function store(UploadedFile $file, string $area, Closure $persist, array &$stored): mixed
    {
        [$kind, $mime] = self::identify($file) ?? throw new RuntimeException(self::REFUSED);

        $upload = $kind === 'PHOTO' ? self::shrunk($file, $mime) : $file;
        $mime = $upload === $file ? $mime : 'image/jpeg';
        $name = $kind === 'FILE' ? self::cleanName($file->getClientOriginalName()) : null;
        $directory = $area.($kind === 'PHOTO' ? '-photos' : '-files');

        try {
            return app(AttachmentStore::class)->store(
                $upload,
                $directory,
                $upload->hashName(),
                static function (string $path) use ($persist, &$stored, $kind, $mime, $upload, $name): mixed {
                    $stored[] = $path;

                    return $persist(['path' => $path, 'kind' => $kind, 'mime' => $mime, 'size' => (int) $upload->getSize(), 'name' => $name]);
                },
                ceiling: self::storageCeilingBytes(),
                usage: self::usedBytes(...),
                fullMessage: self::FULL,
            );
        } finally {
            if ($upload !== $file) {
                @unlink((string) $upload->getRealPath());
            }
        }
    }

    /**
     * A group or group chat photo: the middle square of whatever was picked,
     * redrawn at 256 px as a JPEG, so a list of groups never loads anything
     * heavy. Stored under `{area}-photos/` inside Community and Chat's budget.
     *
     * @return array{path: string, size: int}
     */
    public static function storeAvatar(UploadedFile $file, string $area): array
    {
        $found = self::identify($file);

        if ($found === null || $found[0] !== 'PHOTO') {
            throw new RuntimeException('Pick a photo (JPEG, PNG, GIF or WebP).');
        }
        if ((int) $file->getSize() > self::maxFileBytes()) {
            throw new RuntimeException('That photo is '.HrisFormat::bytes((int) $file->getSize()).'. The limit is '.HrisFormat::bytes(self::maxFileBytes()).'.');
        }

        $square = self::redraw($file, self::AVATAR_EDGE, true);

        try {
            return app(AttachmentStore::class)->store(
                $square,
                $area.'-photos',
                $square->hashName(),
                static fn (string $path): array => ['path' => $path, 'size' => (int) $square->getSize()],
                ceiling: self::storageCeilingBytes(),
                usage: self::usedBytes(...),
                fullMessage: self::FULL,
            );
        } finally {
            @unlink((string) $square->getRealPath());
        }
    }

    /**
     * Removes files whose rows are gone. A failure is logged and left for the
     * file check to report; it never fails the action that removed the rows.
     *
     * @param  list<string>  $paths
     */
    public static function discard(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $disk = Storage::disk('local');
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    Log::error('[social] attachment cleanup failed', ['path' => $path]);
                }
            } catch (Throwable $error) {
                Log::error('[social] attachment cleanup failed', ['path' => $path, 'error' => $error->getMessage()]);
            }
        }
    }

    /**
     * A stored photo or file, streamed to someone the caller has already
     * cleared to see it. A photo shows in the page, or downloads for the
     * viewer's Download button. A file always downloads, under the name it
     * was uploaded with. The type header only ever names one of the accepted
     * types, and nosniff stops a browser from guessing another. Both carry the
     * uploads guide's `private, max-age=300`, like DocumentDownloadController.
     */
    public static function stream(string $path, string $mime, bool $download = false, ?string $name = null): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_if($path === '' || ! $disk->exists($path), 404, 'That file was not found.');

        $known = in_array($mime, self::PHOTO_TYPES, true) || isset(self::FILE_TYPES[$mime]);
        $headers = [
            'Content-Type' => $known ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ];

        if ($name !== null) {
            return $disk->download($path, $name, $headers);
        }

        return $download
            ? $disk->download($path, 'photo-'.basename($path), $headers)
            : $disk->response($path, basename($path), $headers);
    }

    /** The app-wide per-file cap, the same key documents and task files read. */
    public static function maxFileBytes(): int
    {
        return UploadLimits::maxFileBytes();
    }

    /** Community and Chat's own storage budget — not the documents and task files ceiling. */
    public static function storageCeilingBytes(): int
    {
        return (int) config('copems.uploads.social_storage_ceiling_bytes');
    }

    /** What Community and Chat already keep on the disk, counted against their own budget. */
    private static function usedBytes(): int
    {
        return app(StorageUsage::class)->bytes();
    }

    /** "report.PDF" stays "report.PDF"; anything that could reach a path or a header goes. */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .');

        return mb_substr($name !== '' ? $name : 'file', 0, 180);
    }

    /** @return array{string, string}|null */
    private static function officeType(string $path, string $extension): ?array
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        [$part, $type] = self::OFFICE_PARTS[$extension];
        $real = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($part) !== false;
        $zip->close();

        return $real ? ['FILE', $type] : null;
    }

    /** The photo itself when it's already small enough or a GIF, or a shrunk JPEG copy. */
    private static function shrunk(UploadedFile $file, string $mime): UploadedFile
    {
        $size = @getimagesize((string) $file->getRealPath());

        if ($mime === 'image/gif' || $size === false || max($size[0], $size[1]) <= self::PHOTO_MAX_EDGE) {
            return $file;
        }

        return self::redraw($file, self::PHOTO_MAX_EDGE, false);
    }

    /**
     * The picture redrawn at most `$edge` on its longest side (or as the
     * middle square, for a group photo), as a JPEG over white, in a temporary
     * file the caller deletes.
     */
    private static function redraw(UploadedFile $file, int $edge, bool $square): UploadedFile
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new RuntimeException('That photo is too big to use. Try a smaller one.');
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new RuntimeException('Could not read that photo.');
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        [$cropX, $cropY, $cropW, $cropH] = [0, 0, $width, $height];

        if ($square) {
            $side = min($width, $height);
            [$cropX, $cropY, $cropW, $cropH] = [intdiv($width - $side, 2), intdiv($height - $side, 2), $side, $side];
        }

        $scale = min(1.0, $edge / max($cropW, $cropH));
        // Half-up, the way the browser's Math.round does it.
        $newWidth = max(1, (int) floor($cropW * $scale + 0.5));
        $newHeight = max(1, (int) floor($cropH * $scale + 0.5));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // Over white, so a see-through corner doesn't turn black as a JPEG.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $newWidth, $newHeight, $cropW, $cropH);

        $temp = (string) tempnam(sys_get_temp_dir(), 'social');
        $written = imagejpeg($canvas, $temp, 85);
        imagedestroy($canvas);
        imagedestroy($source);

        if (! $written) {
            @unlink($temp);
            throw new RuntimeException('Could not read that photo.');
        }

        return new UploadedFile($temp, 'photo.jpg', 'image/jpeg', null, true);
    }
}
