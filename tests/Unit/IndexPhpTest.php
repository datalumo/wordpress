<?php

it('has a silence is golden index.php in every first-party folder', function () {
    $root = dirname(__DIR__, 2);
    $skip = ['.git', '.github', '.slimm', '.phpunit.cache', '.wordpress-org', 'vendor', 'build'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($skip): bool {
                return ! $current->isDir() || ! in_array($current->getFilename(), $skip, true);
            },
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $missing = [];

    foreach (array_merge([$root], iterator_to_array($iterator)) as $file) {
        $dir = $file instanceof SplFileInfo
            ? ($file->isDir() ? $file->getPathname() : null)
            : $file;

        if ($dir === null) {
            continue;
        }

        $index = $dir.'/index.php';

        if (! is_file($index) || ! str_contains((string) file_get_contents($index), 'Silence is golden')) {
            $missing[] = $dir;
        }
    }

    expect($missing)->toBe([]);
});
