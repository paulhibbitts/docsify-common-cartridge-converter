<?php
require_once __DIR__ . '/vendor/autoload.php';
use DC\CartridgeParser;
use DC\DocsifyBuilder;

$files = glob('/Users/paulhibbitts/Desktop/cc-test imports/*.{imscc,zip}', GLOB_BRACE);
sort($files);

$pass = 0; $fail = 0;

foreach ($files as $file) {
    $name = basename($file);
    echo "=== $name ===\n";

    $tmpDir = sys_get_temp_dir() . '/dc_test_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new Exception('Not a valid ZIP');
        $zip->extractTo($tmpDir);
        $zip->close();

        $parser = new CartridgeParser($tmpDir);
        echo "  Title:    " . ($parser->courseTitle ?: '(none)') . "\n";
        echo "  Modules:  " . count($parser->modules) . "\n";

        $builder = new DocsifyBuilder($parser, true, true, true); // skipFiles, skipImages, stripNumbering
        $zipPath = $builder->build();

        // Check for stray bracket shortcodes or Grav-only syntax that shouldn't appear in
        // flat Docsify output, and confirm every generated page is genuinely flat (no "/" in
        // its zip path apart from the shared images/ and files/ folders).
        $badPaths   = [];
        $shortcodes = [];
        $zip2 = new ZipArchive();
        $zip2->open($zipPath);
        for ($i = 0; $i < $zip2->numFiles; $i++) {
            $entryName = $zip2->getNameIndex($i);
            $content   = $zip2->getFromIndex($i);
            if (str_ends_with($entryName, '.md')
                && strpos($entryName, '/') !== false) {
                $badPaths[] = $entryName;
            }
            if ($content && preg_match_all('/\[(objectives|key-takeaways|reflection)\]/', $content, $m)) {
                foreach ($m[1] as $sc) $shortcodes[$sc] = true;
            }
        }
        $zip2->close();
        unlink($zipPath);

        if ($badPaths)   echo "  NESTED PAGE PATHS: " . implode(', ', $badPaths) . "\n";
        if ($shortcodes) echo "  Bracket shortcodes leaked: " . implode(', ', array_keys($shortcodes)) . "\n";
        if ($builder->warnings) foreach ($builder->warnings as $w) echo "  [warn] $w\n";
        echo "  Pages: " . $builder->pageCount . "\n";
        echo "  OK\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . " (line " . $e->getLine() . " in " . basename($e->getFile()) . ")\n";
        $fail++;
    }

    // cleanup
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    rmdir($tmpDir);

    echo "\n";
}

echo "==============================\n";
echo "Pass: $pass  Fail: $fail  Total: " . ($pass + $fail) . "\n";
