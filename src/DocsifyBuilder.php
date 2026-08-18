<?php
namespace DC;

class DocsifyBuilder
{
    private CartridgeParser  $parser;
    private ContentConverter $converter;

    public array $warnings      = [];
    public int   $pageCount     = 0;
    public int   $droppedCount  = 0;
    public array $droppedByType = [];

    private array $files           = []; // flat path (e.g. "home.md", "images/x.png") => content
    private array $imageData       = []; // "images/filename" => binary
    private array $attachmentFiles = []; // "files/filename" => local path

    private bool $skipFiles;
    private bool $skipImageDownload;
    private bool $stripTitleNumbering;

    private array $pendingImages    = []; // ['filename' (already globally unique), 'localPath'|null, 'url'|null]
    private int   $imageFailures    = 0;
    private int   $externalUrlCount = 0;
    private int   $attachmentCount  = 0;

    private array $usedPageFilenames  = []; // filename stem => count, dedup for page .md filenames
    private array $usedImageNames     = []; // filename => count, dedup for the shared images/ folder
    private array $usedAttachmentNames = []; // filename => count, dedup for the shared files/ folder
    private array $slugToFile         = []; // Canvas wiki-page slug => flat filename (no .md), for $WIKI_REFERENCE$ links

    // Sidebar entries, in build order:
    //  ['type'=>'link',  'label'=>string, 'file'=>string]
    //  ['type'=>'group', 'label'=>string, 'children'=>[['label'=>string,'file'=>string], ...]]
    private array $sidebarEntries = [];

    private static array $introKeywords = ['intro', 'introduction', 'welcome', 'how to use', 'getting started', 'about'];

    public function __construct(
        CartridgeParser $parser,
        bool $skipFiles = true,
        bool $skipImageDownload = false,
        bool $stripTitleNumbering = false
    ) {
        $this->parser = $parser;
        // Always portable: this converter has exactly one output mode (flat, shortcode-free
        // Markdown for Docsify), so there's no user-facing toggle for it like the Grav converter has.
        $this->converter           = new ContentConverter($parser->dir, $skipImageDownload, $skipFiles, true);
        $this->skipFiles           = $skipFiles;
        $this->skipImageDownload   = $skipImageDownload;
        $this->stripTitleNumbering = $stripTitleNumbering;
    }

    // Exposes the in-memory Markdown files build() already assembled – used by the
    // preview endpoint, which needs page content without writing an actual zip.
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getImageData(): array
    {
        return $this->imageData;
    }

    public function build(): string
    {
        $this->warnings = array_merge($this->warnings, $this->parser->warnings);

        // Pre-flight: count remote image URLs across all wiki pages. If there are too many
        // for a web request, auto-switch to remote URL mode so the converter never attempts
        // downloads (avoids PHP timeout hangs).
        if (!$this->skipImageDownload) {
            $remoteCount = 0;
            foreach ($this->parser->wikiPages as $html) {
                preg_match_all('/<img[^>]+src=["\']https?:\/\//i', $html, $m);
                $remoteCount += count($m[0]);
            }
            if ($remoteCount > 100) {
                $this->skipImageDownload = true;
                $this->converter         = new ContentConverter($this->parser->dir, true, $this->skipFiles, true);
                $this->warnings[]        = "Course has $remoteCount remote images – too many to download reliably in a web request. Images will use remote URLs instead. Enable \"Skip image download\" to suppress this message.";
            }
        }

        $this->buildModules();
        $this->buildSyllabus();

        $this->downloadPendingImages();
        $this->resolveWikiRefLinks();
        $this->buildSidebar();
        $this->buildConversionNotes();

        $this->warnings = array_merge($this->warnings, $this->converter->warnings);

        return $this->createZip();
    }

    private function cleanTitle(string $title): string
    {
        return $this->stripTitleNumbering ? Helpers::stripLeadingNumbering($title) : $title;
    }

    // ── Page builders ─────────────────────────────────────────────────────────

    private function buildModules(): void
    {
        $modules = $this->parser->modules;

        if (empty($modules)) {
            $this->buildHome(null);
            return;
        }

        // Detect intro module (first module with intro-like title) – its content becomes
        // the home page instead of a separate module entry.
        $introModule    = null;
        $contentModules = $modules;
        $firstTitle     = strtolower($modules[0]['title'] ?? '');
        foreach (self::$introKeywords as $kw) {
            if (strpos($firstTitle, $kw) !== false) {
                $introModule    = $modules[0];
                $contentModules = array_slice($modules, 1);
                break;
            }
        }

        $this->buildHome($introModule);

        // Belt/badge checkpoint modules are filtered out entirely before buildModule() ever
        // runs, so they'd otherwise never appear in conversion-notes.txt's "Dropped Content"
        // section (trackDropped() only tracks item types within a built module) – track them
        // explicitly here so a whole dropped module always leaves a trace.
        $realModules = [];
        foreach ($contentModules as $mod) {
            if ($this->isBeltEarned($mod)) {
                $this->droppedByType['Belt/badge checkpoint module'] = ($this->droppedByType['Belt/badge checkpoint module'] ?? 0) + 1;
                $this->droppedCount++;
                continue;
            }
            $realModules[] = $mod;
        }

        $modN = 1;
        foreach ($realModules as $mod) {
            $this->buildModule($mod, $modN);
            $modN++;
        }
    }

    private function buildHome(?array $mod): void
    {
        $body  = '';
        $links = [];

        $cover = $this->courseCoverImageMarkdown();
        if ($cover) $body .= $cover . "\n\n";

        if ($mod) {
            foreach ($mod['items'] as $item) {
                if ($item['type'] === 'WikiPage') {
                    $html = $this->getWikiHtml($item);
                    if ($html) $body .= $this->collectImages($html) . "\n\n";
                    if ($item['slug']) $this->registerSlug($item['slug'], 'home');
                } elseif ($item['type'] === 'ExternalUrl') {
                    $url = $item['url'] ?? '';
                    if ($url) $links[] = rtrim($this->externalUrlBody($url, $this->cleanTitle($item['title'])));
                    $this->externalUrlCount++;
                } elseif ($item['type'] === 'Attachment') {
                    $links[] = $this->attachmentLink($item);
                    $this->attachmentCount++;
                }
            }
        }

        if ($links) {
            $body .= implode("\n\n", $links) . "\n";
        }

        $title = $mod ? $this->cleanTitle($mod['title']) : ($this->parser->courseTitle ?: 'Home');
        $this->addFile('home.md', "# $title\n\n" . $this->conversionNotice() . trim($body) . "\n");
        $this->sidebarEntries[] = ['type' => 'link', 'label' => 'Home', 'file' => 'home'];
        $this->pageCount++;
        if ($mod) $this->trackDropped($mod);
    }

    // A brief, unmissable notice on the home page – so anyone who later browses the
    // converted site (not just whoever ran the conversion) has context for why formatting
    // or links might look rough. LMS course exports vary too widely in authoring quality
    // for a clean automatic conversion.
    private function conversionNotice(): string
    {
        return "> [!IMPORTANT]\n"
             . "> This course was automatically converted from a Common Cartridge (LMS) export "
             . "and has not yet been adjusted for optimal results. See `conversion-notes.txt` in "
             . "this download for what was excluded or flagged, then remove this notice once "
             . "you're satisfied with the content.\n\n";
    }

    private function buildModule(array $mod, int $n): void
    {
        [$landingItem, $childItems] = $this->splitItems($mod);

        $landingHtml = $landingItem ? $this->getWikiHtml($landingItem) : '';
        $landingBody = $landingHtml ? $this->collectImages($landingHtml) : '';
        // Tracks every Canvas slug this landing page ends up representing – normally just the
        // landing item's own slug, but promotion below can add a second (the promoted child's)
        // without losing the first, so a $WIKI_REFERENCE$ link to either slug still resolves.
        $landingSlugs = [];
        if ($landingItem['slug'] ?? null) $landingSlugs[] = $landingItem['slug'];

        // If the landing is blank and there's exactly one child, promote its content to the
        // landing so a module with only one real page doesn't need a group at all.
        if (trim($landingBody) === '' && count($childItems) === 1) {
            $only = $childItems[0];
            if ($only['type'] === 'WikiPage') {
                $childHtml   = $this->getWikiHtml($only);
                $landingBody = $childHtml ? $this->collectImages($childHtml) : '';
                if ($only['slug']) $landingSlugs[] = $only['slug'];
                $childItems  = [];
            } elseif ($only['type'] === 'ExternalUrl') {
                $url = $only['url'] ?? '';
                $landingBody = $url ? rtrim($this->externalUrlBody($url, $this->cleanTitle($only['title']))) . "\n" : '';
                $childItems  = [];
                $this->externalUrlCount++;
            } elseif ($only['type'] === 'Attachment') {
                $landingBody = $this->attachmentLink($only) . "\n";
                $childItems  = [];
                $this->attachmentCount++;
            }
        }

        // Appended after the single-child promotion check above, so LTI-only "content"
        // never itself counts as real landing content and defeats that flattening.
        $landingBody .= $this->ltiWarning($mod['items']);

        $modTitle   = $this->cleanTitle($mod['title']);
        $hasLanding = trim($landingBody) !== '';

        // A module with no real content at all (only Canvas-only items: quizzes,
        // discussions, ...) contributes nothing to the site – already counted below.
        if (!$hasLanding && empty($childItems)) {
            $this->trackDropped($mod);
            return;
        }

        $modSlug     = Helpers::slugify($modTitle) ?: 'module';
        $landingFile = $this->uniquePageFile(sprintf('module-%02d-%s', $n, $modSlug));

        if ($hasLanding) {
            $this->addFile($landingFile . '.md', "# $modTitle\n\n" . trim($landingBody) . "\n");
            $this->pageCount++;
            foreach ($landingSlugs as $slug) $this->registerSlug($slug, $landingFile);
        }

        $childLinks = [];
        $childN     = 1;
        foreach ($childItems as $item) {
            $childLinks[] = $this->buildChildPage($n, $childN, $item);
            $childN++;
        }

        if (empty($childLinks)) {
            // Single page overall (landing only) – flatten to a plain top-level link, no
            // group header. The flat structure makes this the natural default, not a
            // special case the way the nested Grav builder needed one.
            $this->sidebarEntries[] = ['type' => 'link', 'label' => $modTitle, 'file' => $landingFile];
        } else {
            $children = [];
            if ($hasLanding) {
                $children[] = ['label' => 'Overview', 'file' => $landingFile];
            }
            foreach ($childLinks as $cl) $children[] = $cl;
            $this->sidebarEntries[] = ['type' => 'group', 'label' => $modTitle, 'children' => $children];
        }

        $this->trackDropped($mod);
    }

    private function buildChildPage(int $modN, int $itemN, array $item): array
    {
        $cleanedTitle = $this->cleanTitle($item['title']);
        $titleForSlug = $item['type'] === 'Attachment'
            ? preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $cleanedTitle)
            : $cleanedTitle;
        $slug = Helpers::slugify($titleForSlug) ?: 'page';
        $file = $this->uniquePageFile(sprintf('module-%02d-%02d-%s', $modN, $itemN, $slug));

        if ($item['type'] === 'WikiPage') {
            if ($item['slug']) $this->registerSlug($item['slug'], $file);
            $html = $this->getWikiHtml($item);
            $body = $html ? $this->collectImages($html) : '';
        } elseif ($item['type'] === 'ExternalUrl') {
            $url  = $item['url'] ?? '';
            $body = $url ? $this->externalUrlBody($url, $cleanedTitle) : '';
            $this->externalUrlCount++;
        } elseif ($item['type'] === 'Attachment') {
            $body = $this->attachmentLink($item) . "\n";
            $this->attachmentCount++;
        } else {
            $body = '';
        }

        $this->addFile($file . '.md', "# $cleanedTitle\n\n" . trim($body) . "\n");
        $this->pageCount++;

        return ['label' => $cleanedTitle, 'file' => $file];
    }

    private function attachmentLink(array $item): string
    {
        $cleanedTitle = $this->cleanTitle($item['title']);
        $filePath     = $item['filePath'] ?? '';
        if ($filePath && !$this->skipFiles) {
            $filename = $this->globalUniqueAttachmentName(basename($filePath));
            $this->attachmentFiles["files/$filename"] = $filePath;
            return "[$cleanedTitle](files/$filename)";
        }
        return "**$cleanedTitle** – attached file not included (see conversion-notes.txt)";
    }

    private function buildSyllabus(): void
    {
        $html = $this->parser->syllabusHtml;
        if (!$html || strlen(strip_tags($html)) < 10) return; // no real content – nothing to add

        $body = $this->collectImages($html);
        $this->addFile('syllabus.md', "# Syllabus\n\n" . trim($body) . "\n");
        $this->sidebarEntries[] = ['type' => 'link', 'label' => 'Syllabus', 'file' => 'syllabus'];
        $this->pageCount++;
    }

    // Embeds the course's card image (when the cartridge included one) at the top of the
    // home page – otherwise it would sit unused in the ZIP with nothing linking to it.
    private function courseCoverImageMarkdown(): string
    {
        $p   = $this->parser;
        $alt = $p->courseTitle ?: 'Course image';

        if ($p->courseImagePath) {
            $filename = $this->globalUniqueImageName(basename($p->courseImagePath));
            $this->pendingImages[] = ['filename' => $filename, 'localPath' => $p->courseImagePath, 'url' => null];
            return '![' . $alt . '](images/' . $filename . ')';
        }
        if ($p->courseImageUrl) {
            if (!$this->skipImageDownload) {
                $candidate = basename(parse_url($p->courseImageUrl, PHP_URL_PATH) ?: '') ?: 'course-image';
                $filename  = $this->globalUniqueImageName($candidate);
                $this->pendingImages[] = ['filename' => $filename, 'localPath' => null, 'url' => $p->courseImageUrl];
                return '![' . $alt . '](images/' . $filename . ')';
            }
            return '![' . $alt . '](' . $p->courseImageUrl . ')';
        }
        return '';
    }

    // ── Sidebar / conversion notes ───────────────────────────────────────────

    // _sidebar.md generation is always on – not a user-facing option. A Docsify site with
    // no sidebar has little navigational value, so this is a core part of the basic output,
    // documented in conversion-notes.txt rather than offered as a toggle.
    private function buildSidebar(): void
    {
        $lines = [];
        foreach ($this->sidebarEntries as $entry) {
            if ($entry['type'] === 'link') {
                $lines[] = '- [' . $entry['label'] . '](' . $entry['file'] . ')';
            } else {
                $lines[] = '- **' . $entry['label'] . '**';
                foreach ($entry['children'] as $child) {
                    $lines[] = '  - [' . $child['label'] . '](' . $child['file'] . ')';
                }
            }
        }
        $this->addFile('_sidebar.md', implode("\n", $lines) . "\n");
    }

    private function buildConversionNotes(): void
    {
        $p     = $this->parser;
        $lines = [];

        $addHeader = function (string $title) use (&$lines) {
            $lines[] = $title;
            $lines[] = str_repeat('-', strlen($title));
        };

        $title   = 'Common Cartridge to Docsify Conversion Notes';
        $lines[] = $title;
        $lines[] = str_repeat('=', strlen($title));
        $lines[] = 'Generated: ' . date('Y-m-d');
        $lines[] = '';

        $addHeader('Course Metadata');
        $lines[] = '  Title:   ' . ($p->courseTitle ?: '(none)');
        $lines[] = '  Code:    ' . ($p->courseCode  ?: '(none)');
        $lines[] = '  License: ' . ($p->license     ?: '(not specified)');
        if ($p->licenseUrl) $lines[] = '  License URL: ' . $p->licenseUrl;
        if ($p->courseImagePath || $p->courseImageUrl) {
            $lines[] = '  Course image: embedded at the top of home.md';
        }
        $lines[] = '';

        $addHeader('Structure');
        $lines[] = '  Modules:    ' . count($p->modules);
        $lines[] = '  Wiki pages: ' . count($p->wikiPages);
        $lines[] = '  Pages created: ' . $this->pageCount;
        $imageCount = count($this->pendingImages);
        if ($imageCount > 0) {
            if ($this->skipImageDownload) {
                $localCount = count(array_filter($this->pendingImages, fn($i) => $i['localPath'] !== null));
                $lines[] = '  Images: ' . $localCount . ' local (included in ZIP); external images use remote URLs';
            } else {
                $failNote = $this->imageFailures > 0 ? '; ' . $this->imageFailures . ' failed (see warnings)' : '';
                $lines[] = '  Images: ' . count($this->imageData) . ' downloaded and included in ZIP' . $failNote;
            }
        }
        if ($this->externalUrlCount > 0) {
            $lines[] = '  External URLs:  ' . $this->externalUrlCount . ' (converted to Markdown links)';
        }
        if ($this->attachmentCount > 0) {
            $included = !$this->skipFiles ? 'included in ZIP under files/' : 'not included';
            $lines[] = '  Attachments:    ' . $this->attachmentCount . ' (' . $included . ')';
        }
        $lines[] = '';

        $addHeader('Dropped Content');
        if (empty($this->droppedByType)) {
            $lines[] = '  None.';
        } else {
            foreach ($this->droppedByType as $type => $count) {
                $lines[] = sprintf('  %-30s %d', $type, $count);
            }
            $lines[] = '';
            $lines[] = '  Total dropped items: ' . $this->droppedCount;
        }
        $lines[] = '';

        $addHeader('Next Steps');
        $lines[] = '  1. Copy the contents of this ZIP (the .md files, images/, files/, and';
        $lines[] = '     _sidebar.md) into an existing Docsify or Docsify-This site\'s docs/';
        $lines[] = '     folder – for example one of the docsify-open-course-starter-kit';
        $lines[] = '     templates (github.com/paulhibbitts/docsify-open-course-starter-kit)';
        $lines[] = '  2. If that site already has its own _sidebar.md, merge these entries into';
        $lines[] = '     it rather than overwriting the file';
        $lines[] = '  3. Review this file for any warnings or manual fixes needed';
        $lines[] = '';

        $addHeader('Conversion Settings');
        $lines[] = '  Attached files:  ' . ($this->skipFiles ? 'skipped' : 'included in ZIP under files/');
        $lines[] = '  Image download:  ' . ($this->skipImageDownload ? 'skipped – images kept as remote URLs' : 'downloaded and bundled in ZIP');
        $lines[] = '  Numbered titles: ' . ($this->stripTitleNumbering ? 'cleaned up (leading numbering stripped)' : 'left as-is');
        $lines[] = '  Sidebar:         _sidebar.md generated automatically (always included)';
        $lines[] = '';

        if (!empty($this->warnings)) {
            $addHeader('Warnings');
            foreach ($this->warnings as $w) {
                $lines[] = '  [warn] ' . $w;
            }
            $lines[] = '';
        }

        $addHeader('Known Limitations');
        $lines[] = '  - Quizzes and discussions are not supported and have been dropped.';
        $lines[] = '  - Internal Canvas page links are rewritten to the converted page when the target is included in this course; otherwise the link points to "#" as a placeholder.';
        $lines[] = '  - LTI tool links appear as plain links to the original tool; authentication context is not preserved.';
        $lines[] = '';

        $this->addFile('conversion-notes.txt', implode("\n", $lines) . "\n");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    // Converts HTML to Markdown and, since every page now shares one flat images/ folder
    // instead of living in its own Grav page folder, rewrites each image/file reference to
    // a course-wide unique filename (ContentConverter's own dedup only covers one page at a
    // time) and prefixes it with images/ or files/.
    private function collectImages(string $html): string
    {
        $md = $this->converter->convert($html);

        foreach ($this->converter->pendingImages as $img) {
            $unique = $this->globalUniqueImageName($img['filename']);
            $md     = str_replace('](' . $img['filename'] . ')', '](images/' . $unique . ')', $md);
            $this->pendingImages[] = ['filename' => $unique, 'localPath' => $img['localPath'], 'url' => $img['url']];
        }

        foreach ($this->converter->pendingFiles as $file) {
            $unique = $this->globalUniqueAttachmentName($file['filename']);
            $md     = str_replace('](' . $file['filename'] . ')', '](files/' . $unique . ')', $md);
            $this->attachmentFiles['files/' . $unique] = $file['localPath'];
        }

        return $this->constrainMarkerIcons($md);
    }

    // A small icon immediately paired with a bold label ("<img> <strong>READ</strong>", or a
    // bold span that wraps both together like "**<img> SUBMIT: Due...**") is a marker/bullet
    // idiom, not real content – Canvas's own theme CSS keeps these visually consistent
    // regardless of each icon file's actual pixel dimensions, but that CSS doesn't carry over
    // to plain Markdown, so mismatched source files (e.g. one icon authored at 128×111 next to
    // siblings at ~60×50) become visibly inconsistent once rendered. Cap just this pattern to a
    // small fixed width via raw HTML – Markdown has no image-size syntax of its own – leaving
    // every other image (real content photos, diagrams) untouched at native size. Runs after
    // the images/ path rewrite above, since it turns the Markdown image syntax those rewrites
    // target into raw HTML.
    private function constrainMarkerIcons(string $md): string
    {
        // Variant 1: image directly followed by its own short bold label.
        $md = preg_replace(
            '/!\[([^\]]*)\]\(([^)\s]+)\) ?(\*\*[^*\n]{1,40}\*\*)/',
            '<img src="$2" alt="$1" width="28"> $3',
            $md
        );
        // Variant 2: a bold span that wraps the image and its label together.
        $md = preg_replace(
            '/(\*\*)!\[([^\]]*)\]\(([^)\s]+)\) /',
            '$1<img src="$3" alt="$2" width="28"> ',
            $md
        );
        return $md;
    }

    private function uniquePageFile(string $stem): string
    {
        $count = ($this->usedPageFilenames[$stem] ?? 0) + 1;
        $this->usedPageFilenames[$stem] = $count;
        return $count === 1 ? $stem : $stem . '-' . $count;
    }

    private function globalUniqueImageName(string $base): string
    {
        return $this->dedupeFilename($this->usedImageNames, $base);
    }

    private function globalUniqueAttachmentName(string $base): string
    {
        return $this->dedupeFilename($this->usedAttachmentNames, $base);
    }

    // Replaces spaces so Markdown image/link syntax doesn't break (a bare space in
    // `![alt](name with spaces.jpg)` isn't a valid URL to most Markdown parsers, including
    // marked.js). ContentConverter::uniqueFilename() already does this for regular page
    // images, but the course cover image (see courseCoverImageMarkdown()) is handled
    // directly here and never passes through that sanitization, so it needs its own.
    private function dedupeFilename(array &$used, string $base): string
    {
        $base  = preg_replace('/\s+/', '-', $base);
        $count = ($used[$base] ?? 0) + 1;
        $used[$base] = $count;
        if ($count === 1) return $base;
        $ext  = pathinfo($base, PATHINFO_EXTENSION);
        $stem = pathinfo($base, PATHINFO_FILENAME);
        return $stem . '-' . $count . ($ext !== '' ? '.' . $ext : '');
    }

    // Record which flat filename a Canvas wiki-page slug ended up at, so inline
    // $WIKI_REFERENCE$/pages/{slug} links can be resolved once every page exists.
    private function registerSlug(string $slug, string $file): void
    {
        $this->slugToFile[$slug] = $file;
    }

    // Substitute %%WIKIREF:slug%% placeholders (emitted by ContentConverter for
    // $WIKI_REFERENCE$ links) with the target page's flat filename, now that every page's
    // filename is known. Unresolved slugs (page not included in this course) strip the
    // surrounding [text](%%) link syntax to leave plain text – safer than a dead link.
    private function resolveWikiRefLinks(): void
    {
        foreach ($this->files as $path => $content) {
            if (!str_ends_with($path, '.md') || strpos($content, '%%WIKIREF:') === false) continue;
            $resolved = preg_replace_callback(
                '/%%WIKIREF:([^%]+)%%/',
                fn($m) => $this->slugToFile[$m[1]] ?? '%%DEAD%%',
                $content
            );
            $resolved = preg_replace('/\[([^\]\n]+)\]\(%%DEAD%%\)/', '$1', $resolved);
            $resolved = str_replace('%%DEAD%%', '#', $resolved);
            $this->files[$path] = $resolved;
        }
    }

    private function downloadPendingImages(): void
    {
        $deadline = microtime(true) + 90; // 90s total budget for remote image downloads

        foreach ($this->pendingImages as $img) {
            $zipPath = 'images/' . $img['filename'];

            if ($img['localPath'] !== null) {
                $data = @file_get_contents($img['localPath']);
                if ($data !== false) $this->imageData[$zipPath] = $data;
            } elseif ($img['url'] !== null) {
                if (microtime(true) >= $deadline) {
                    $this->imageFailures++;
                    continue; // count remaining as failures silently
                }
                $data = $this->downloadImage($img['url']);
                if ($data !== null) {
                    $this->imageData[$zipPath] = $data;
                } else {
                    $this->imageFailures++;
                    $this->warnings[] = 'Image download failed: ' . $img['url'];
                }
            }
        }

        if ($this->imageFailures > 0 && microtime(true) >= $deadline) {
            $this->warnings[] = 'Image download time limit reached – some remote images were skipped. Use "Skip image download" to keep remote URLs instead.';
        }
    }

    private function downloadImage(string $url): ?string
    {
        if (!$this->isSafeImageUrl($url)) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_CONNECTTIMEOUT  => 3,   // 3s to establish connection
                CURLOPT_TIMEOUT         => 5,   // 5s total transfer
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_MAXREDIRS       => 3,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; DocsifyCartridgeConverter/1.0)',
            ]);
            $data     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($data && strlen($data) > 0 && $httpCode === 200) ? $data : null;
        }

        // Fallback: file_get_contents (less reliable timeout enforcement)
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $ctx);
        return ($data !== false && strlen($data) > 0) ? $data : null;
    }

    // SSRF guard: an <img> src comes straight from uploaded course content, so before
    // fetching it, reject anything that isn't plain http(s) (blocks file://, etc. – this
    // matters most for the file_get_contents() fallback above, which would otherwise just
    // read a local file directly) or whose host resolves to a private, loopback, or
    // link-local address (e.g. a cloud metadata endpoint).
    private function isSafeImageUrl(string $url): bool
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $ip = gethostbyname($host);
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function getWikiHtml(array $item): string
    {
        $slug = $item['slug'] ?? null;
        if (!$slug) return '';
        return $this->parser->wikiPages[$slug] ?? '';
    }

    private function isBeltEarned(array $mod): bool
    {
        $title = strtolower($mod['title']);
        // Single-item modules that are just "X Earned" or "X Badge" checkpoints
        $supported = ['WikiPage', 'ExternalUrl', 'Attachment'];
        $contentItems = array_filter($mod['items'], fn($i) => in_array($i['type'], $supported, true));
        return count($contentItems) <= 1
            && (strpos($title, 'earned') !== false || strpos($title, 'badge') !== false);
    }

    private function ltiWarning(array $items): string
    {
        $ltiItems = array_filter($items, fn($i) => $i['type'] === 'ContextExternalTool');
        if (empty($ltiItems)) return '';
        $titles = implode(', ', array_map(fn($i) => '"' . $i['title'] . '"', $ltiItems));
        return "\n\n> [!WARNING]\n> The following LTI tool item(s) could not be converted and must be accessed in the original course: " . $titles . "\n";
    }

    private function trackDropped(array $mod): void
    {
        $droppable = ['Quizzes::Quiz', 'DiscussionTopic', 'Assignment', 'ContextExternalTool'];
        foreach ($mod['items'] as $item) {
            if (in_array($item['type'], $droppable, true)) {
                $this->droppedByType[$item['type']] = ($this->droppedByType[$item['type']] ?? 0) + 1;
                $this->droppedCount++;
            }
        }
    }

    // Render an external URL as an embed or link for known video hosts, or a plain link
    private function externalUrlBody(string $url, string $title): string
    {
        if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/', $url, $m)
            || preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $this->converter->youtubeIframe($m[1], $title) . "\n";
        }
        if (str_contains($url, 'vimeo.com')) {
            $label = $title ?: 'Watch video on Vimeo';
            return '> [' . $label . '](' . $url . ")\n";
        }
        return "[$title]($url)\n";
    }

    // Split a module's items into a landing WikiPage (first indent-0 WikiPage) and the rest
    private function splitItems(array $mod): array
    {
        $landingItem = null;
        $childItems  = [];
        foreach ($mod['items'] as $item) {
            if (!in_array($item['type'], ['WikiPage', 'ExternalUrl', 'Attachment'], true)) continue;
            if ($item['type'] === 'WikiPage' && $item['indent'] === 0 && $landingItem === null) {
                $landingItem = $item;
            } else {
                $childItems[] = $item;
            }
        }
        return [$landingItem, $childItems];
    }

    private function addFile(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }

    private function createZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dc_zip_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the output ZIP file on the server.');
        }

        foreach ($this->files as $path => $content) {
            $zip->addFromString($path, $content);
        }

        foreach ($this->imageData as $path => $data) {
            $zip->addFromString($path, $data);
        }

        foreach ($this->attachmentFiles as $zipPath => $localPath) {
            if (file_exists($localPath)) {
                $zip->addFile($localPath, $zipPath);
            }
        }

        $zip->close();
        return $tmp;
    }
}
