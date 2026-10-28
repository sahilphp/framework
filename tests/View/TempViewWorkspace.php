<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;

/**
 * Throwaway view installation for tests: one or more view roots plus a
 * compiled-template cache, all under a unique temp directory that is removed
 * afterwards. Tests never read or write the project's `views/`.
 */
final class TempViewWorkspace
{
    private string $base;

    /** @var list<string> */
    private array $roots = [];

    private string $cache;

    public function __construct(private readonly int $rootCount = 1, private readonly string $extension = '.sahil.php')
    {
        $this->base = sys_get_temp_dir().'/sahil-view-'.bin2hex(random_bytes(6));

        if (!mkdir($this->base, 0o777, true) && !is_dir($this->base)) {
            throw new \RuntimeException(sprintf('Unable to create temp workspace [%s].', $this->base));
        }

        for ($index = 0; $index < $this->rootCount; $index++) {
            $root = $this->base.'/views'.$index;
            mkdir($root, 0o777, true);
            $this->roots[] = $root;
        }

        $this->cache = $this->base.'/cache';
        mkdir($this->cache, 0o777, true);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function root(int $index = 0): string
    {
        return $this->roots[$index];
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    public function cache(): string
    {
        return $this->cache;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Write a template by name (`pages.home` or `pages/home`) into a root.
     */
    public function write(string $name, string $contents, int $root = 0): string
    {
        $relative = str_replace('.', '/', $name).$this->extension;
        $path = $this->roots[$root].'/'.$relative;

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Raw path inside a root, for fixtures the name validator must refuse.
     */
    public function path(string $relative, int $root = 0): string
    {
        return $this->roots[$root].'/'.$relative;
    }

    /**
     * @return list<string>
     */
    public function resolver(): TemplateResolver
    {
        return new TemplateResolver($this->roots, $this->extension);
    }

    public function compiler(): TemplateCompiler
    {
        return new TemplateCompiler($this->cache);
    }

    public function engine(?AssetManagerContract $assets = null): ViewEngine
    {
        return new ViewEngine($this->resolver(), $this->compiler(), $assets);
    }

    /**
     * Path of the compiled artifact for a template name.
     */
    public function compiled(string $name): string
    {
        return $this->compiler()->compiledPath($this->resolver()->resolve($name));
    }

    public function destroy(): void
    {
        self::remove($this->base);
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
