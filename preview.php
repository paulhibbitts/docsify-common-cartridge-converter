<?php
require_once __DIR__ . '/vendor/autoload.php';

use DC\CartridgeParser;
use DC\DocsifyBuilder;

set_time_limit(300);
ini_set('memory_limit', '512M');

const MAX_UPLOAD_BYTES        = 500 * 1024 * 1024;
const MIN_PREVIEW_PAGE_LENGTH = 500;
const MAX_PREVIEW_PAGES       = 5;
const MAX_PREVIEW_IMAGE_BYTES = 2 * 1024 * 1024;
const IMAGE_EXTENSIONS        = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'avif'];

header('Content-Type: application/json');

// ── Upload validation ──────────────────────────────────────────────────────

if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    sendError(400, 'The server rejected the upload – the file likely exceeds the server post_max_size limit.');
}

$fileError = $_FILES['imscc']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileError !== UPLOAD_ERR_OK) {
    sendError(400, 'No file was uploaded.');
}

if ($_FILES['imscc']['size'] > MAX_UPLOAD_BYTES) {
    sendError(400, 'File exceeds the 500 MB limit.');
}

$uploadExt = strtolower(pathinfo($_FILES['imscc']['name'], PATHINFO_EXTENSION));
if ($uploadExt !== 'imscc' && $uploadExt !== 'zip') {
    sendError(400, 'Please upload a .imscc or .zip file exported from Canvas or another LMS.');
}

// ── Extract to a temp directory – CartridgeParser reads from disk, not a string ──

$skipFiles           = !empty($_POST['skip_files']);
$skipImageDownload   = !empty($_POST['skip_image_download']);
$stripTitleNumbering = !empty($_POST['strip_title_numbering']);

$tmpDir = sys_get_temp_dir() . '/dc_preview_' . uniqid();
mkdir($tmpDir, 0700, true);

$zip = new \ZipArchive();
if ($zip->open($_FILES['imscc']['tmp_name']) !== true) {
    cleanupDir($tmpDir);
    sendError(400, 'The uploaded file does not appear to be a valid .imscc (ZIP) file.');
}

$toExtract = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entryName = $zip->getNameIndex($i);
    if (shouldExtractZipEntry($entryName, $skipFiles)) {
        $toExtract[] = $entryName;
    }
}
$zip->extractTo($tmpDir, $toExtract);
$zip->close();

// ── Parse and build ────────────────────────────────────────────────────────

try {
    $parser = new CartridgeParser($tmpDir);
    if (!$parser->isValid()) {
        throw new \Exception('No supported content found in this cartridge.');
    }

    $slugOverride = trim($_POST['course_slug'] ?? '');
    if ($slugOverride) {
        $parser->courseSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slugOverride));
    }

    $builder = new DocsifyBuilder($parser, $skipFiles, $skipImageDownload, $stripTitleNumbering);
    $zipPath = $builder->build();
    @unlink($zipPath); // preview only needs the in-memory pages, not the zip file itself
} catch (\Throwable $e) {
    cleanupDir($tmpDir);
    sendError(422, $e->getMessage());
}

cleanupDir($tmpDir);

$imageData = $builder->getImageData();
$pages     = pickPreviewPages($builder->getFiles(), $imageData);
if (!$pages) {
    sendError(404, 'No substantial content page was found to preview.');
}

echo json_encode(['pages' => array_map(fn($page) => formatPreviewPage($page, $imageData), $pages)]);
exit;

// ── Helpers ──────────────────────────────────────────────────────────────────

function sendError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

// Mirrors convert.php: always extract course images and page images, only extract
// other attachments (PDFs, videos, etc.) when "Skip attached files" is off.
function shouldExtractZipEntry(string $entryName, bool $skipFiles): bool
{
    if (strpos($entryName, 'web_resources/') !== 0) {
        return true; // not a web_resources/ entry – part of the core course structure
    }
    $isCourseImage = strpos($entryName, 'web_resources/course_image/') === 0;
    $entryExt      = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
    $isImage       = in_array($entryExt, IMAGE_EXTENSIONS, true);
    return $isCourseImage || $isImage || !$skipFiles;
}

/**
 * Samples up to MAX_PREVIEW_PAGES content pages. home.md goes first when it has real
 * content (see homePageCandidate()), followed by one page per module – DocsifyBuilder
 * names every module/child page "module-{NN}-..." or "module-{NN}-{itemNN}-...", so the
 * leading module number groups a landing page with its own children. syllabus.md is
 * always excluded from the sample (it isn't module content).
 *
 * Every file DocsifyBuilder produces already has real content – unlike the Grav builder,
 * it never writes a placeholder "add an introduction here" stub – so no separate stub
 * filter is needed here beyond the length check, which just prefers meatier pages.
 */
function pickPreviewPages(array $files, array $imageData): array
{
    $selected = [];

    $home = homePageCandidate($files);
    if ($home) {
        $selected[] = $home;
    }

    $candidatesByModule = [];
    foreach ($files as $path => $content) {
        if (!preg_match('/^module-(\d+)-/', $path, $m)) {
            continue;
        }

        $body = cleanPageBody($content);
        if (strlen($body) < MIN_PREVIEW_PAGE_LENGTH) {
            continue; // skip thin pages
        }

        $candidatesByModule[$m[1]][] = ['path' => $path, 'content' => $content, 'body' => $body];
    }

    foreach ($candidatesByModule as $candidates) {
        $selected[] = pickBestCandidate($candidates, $imageData);
    }

    return array_slice($selected, 0, MAX_PREVIEW_PAGES);
}

// home.md only has real content when the first module's title was detected as an intro
// (see DocsifyBuilder::buildModules()'s $introKeywords check) – in that case it's the
// site's actual landing page and worth showing first. Otherwise it's just the bare
// conversion notice with nothing else, and not worth including at all.
function homePageCandidate(array $files): ?array
{
    if (!isset($files['home.md'])) return null;
    $body = cleanPageBody($files['home.md']);
    if (strlen($body) < MIN_PREVIEW_PAGE_LENGTH) {
        return null; // no intro module detected – nothing but the conversion notice
    }
    return ['path' => 'home.md', 'content' => $files['home.md'], 'body' => $body];
}

// Prefers the first candidate in a module that has at least one embeddable image, so someone
// browsing the preview is more likely to see real course content, not just text – falls back
// to the first substantial candidate (the previous default) if none of them have one.
function pickBestCandidate(array $candidates, array $imageData): array
{
    foreach ($candidates as $candidate) {
        if (matchedImageFilenames($candidate['body'], $imageData)) {
            return $candidate;
        }
    }
    return $candidates[0];
}

function formatPreviewPage(array $page, array $imageData): array
{
    [$title, $body] = extractTitleAndBody($page['content']);
    $markdown = cleanPageBody($page['content'], $body);
    return [
        'path'     => $page['path'],
        'title'    => $title,
        'markdown' => $markdown,
        'images'   => embeddedImagesForPage($markdown, $imageData),
    ];
}

// Local (non-URL) image filenames referenced in $markdown that have matching binary data
// already downloaded by build(), small enough to embed inline. Shared by pickBestCandidate()
// (just needs to know if any exist) and embeddedImagesForPage() (needs the actual bytes).
// All images live in one shared images/ folder, so no per-page path math is needed.
function matchedImageFilenames(string $markdown, array $imageData): array
{
    preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $markdown, $imageRefs);

    $matched = [];
    foreach (array_unique($imageRefs[1]) as $ref) {
        if (preg_match('#^https?://#i', $ref)) {
            continue; // already a full URL – the browser can load it directly
        }
        if (!isset($imageData[$ref])) {
            continue; // no matching downloaded binary – left for the placeholder to handle
        }
        if (strlen($imageData[$ref]) > MAX_PREVIEW_IMAGE_BYTES) {
            continue; // too large to embed inline
        }
        $matched[] = $ref;
    }
    return $matched;
}

// "images/filename" => "data:image/...;base64,..." for every image matchedImageFilenames()
// found – lets the preview show real images instead of a placeholder, no extra request needed.
function embeddedImagesForPage(string $markdown, array $imageData): array
{
    $images = [];
    foreach (matchedImageFilenames($markdown, $imageData) as $ref) {
        $images[$ref] = 'data:' . imageMimeType($ref) . ';base64,' . base64_encode($imageData[$ref]);
    }
    return $images;
}

function imageMimeType(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'svg'         => 'image/svg+xml',
        'bmp'         => 'image/bmp',
        default       => 'application/octet-stream',
    };
}

// DocsifyBuilder pages start with a "# Title" heading (no YAML frontmatter – Docsify
// derives the title from that heading itself). Splits it from the rest of the body.
function extractTitleAndBody(string $pageContent): array
{
    if (preg_match('/^#\s+(.+?)\s*\n(.*)$/s', ltrim($pageContent), $m)) {
        return [$m[1], $m[2]];
    }
    return ['Untitled', $pageContent];
}

// Strips the auto-generated "this course was automatically converted..." notice
// DocsifyBuilder prepends to home.md – real, useful context in the actual download, but
// redundant in a preview that's already labeled "Preview sketch" and explains its own
// limitations elsewhere. Matches on the notice's specific wording, not just its
// [!IMPORTANT] type, so real course content using the same callout is never hidden.
function cleanPageBody(string $pageContent, ?string $body = null): string
{
    $body ??= extractTitleAndBody($pageContent)[1];
    return preg_replace_callback(
        '/^> \[!IMPORTANT\]\n(?:>[^\n]*\n)*\n*/m',
        fn($m) => str_contains($m[0], 'automatically converted from a Common Cartridge') ? '' : $m[0],
        $body
    );
}
