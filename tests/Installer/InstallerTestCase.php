<?php

declare(strict_types=1);

namespace Sahil\Tests\Installer;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sahil\Installer\ConsoleIO;
use SplFileInfo;

/**
 * Temporary starter-kit fixture for installer tests.
 */
abstract class InstallerTestCase extends TestCase
{
    protected string $workspace;

    protected string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/sahil-installer-'.bin2hex(random_bytes(6));
        $this->source = $this->workspace.'/starter-kit';

        self::assertTrue(mkdir($this->source, 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    protected function makeSource(): void
    {
        $files = [
            '.env.example' => "APP_KEY=\nAPP_ENV=development\n",
            '.git/config' => "[core]\n",
            '.gitignore' => "/vendor/\n/node_modules/\n",
            '.sahil-ui.json' => json_encode(['profile' => 'both'], JSON_THROW_ON_ERROR),
            'app/Http/HomeController.php' => "<?php\n",
            'bin/sahil' => "#!/usr/bin/env php\n<?php\n",
            'bootstrap/app.php' => "<?php\n",
            'composer.json' => json_encode([
                'name' => 'sahilphp/starter-kit',
                'type' => 'project',
            ], JSON_THROW_ON_ERROR),
            'composer.json.backup' => "{}\n",
            'package.json' => json_encode([
                'name' => 'fixture',
                'private' => true,
                'devDependencies' => [
                    '@tailwindcss/vite' => '^4.3.3',
                    'bootstrap' => '^5.3.8',
                    'tailwindcss' => '^4.3.3',
                    'vite' => '^8.3.5',
                ],
            ], JSON_THROW_ON_ERROR),
            'package-lock.json' => "{}\n",
            'postcss.config.cjs' => "module.exports = {};\n",
            'postcss.config.js' => "module.exports = {};\n",
            'public/build/assets/bootstrap.css' => "bootstrap\n",
            'public/build/assets/tailwind.css' => "tailwind\n",
            'public/hot' => "http://127.0.0.1:5173\n",
            'node_modules/vite/package.json' => "{}\n",
            'resources/css/app.css' => "/* legacy tailwind */\n",
            'resources/css/bootstrap-overrides.css' => "/* overrides */\n",
            'resources/css/bootstrap.css' => "/* bootstrap */\n",
            'resources/css/tailwind.css' => "@import 'tailwindcss';\n",
            'resources/js/bootstrap-sass.js' => "import 'bootstrap';\n",
            'resources/js/bootstrap-starter.js' => "import 'bootstrap';\n",
            'resources/js/bootstrap.js' => "import 'bootstrap';\n",
            'resources/sass/bootstrap.scss' => "@import 'bootstrap';\n",
            'storage/README.md' => "storage\n",
            'storage/framework/views/.gitkeep' => '',
            'storage/framework/views/compiled.php' => "<?php\n",
            'storage/logs/.gitkeep' => '',
            'storage/logs/app.log' => "log\n",
            'tailwind.config.cjs' => "module.exports = {};\n",
            'tailwind.config.js' => "module.exports = {};\n",
            'vendor/autoload.php' => "<?php\n",
            'vite.bootstrap.config.js' => "module.exports = {};\n",
            'vite.config.js' => "module.exports = {};\n",
        ];

        foreach ($files as $relative => $contents) {
            $this->writeSource($relative, $contents);
        }
    }

    protected function writeSource(string $relative, string $contents): void
    {
        $path = $this->source.'/'.$relative;

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o700, true) && !is_dir(dirname($path))) {
            self::fail(sprintf('Unable to create directory [%s].', dirname($path)));
        }

        file_put_contents($path, $contents);
    }

    /**
     * @param list<string> $input
     */
    protected function consoleIO(array $input, bool $interactive): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');

        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);

        fwrite($in, implode(PHP_EOL, $input).PHP_EOL);
        rewind($in);

        return [new ConsoleIO($in, $out, $err, $interactive), $out, $err];
    }

    /**
     * @param resource $stream
     */
    protected function streamContents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);

        return $contents === false ? '' : $contents;
    }

    protected function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
