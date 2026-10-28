<?php

declare(strict_types=1);

namespace Sahil\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use Sahil\Tests\Ui\UiTestCase;
use Sahil\Theme\ThemeException;
use Sahil\Theme\ThemeInstaller;
use Sahil\Theme\ThemeTokens;
use Sahil\Ui\Profile;
use Sahil\Ui\ProfilePlan;

final class ThemeInstallerTest extends UiTestCase
{
    /**
     * @return array<string, array{Profile, list<string>, list<string>, list<string>}>
     */
    public static function profileFiles(): array
    {
        return [
            'tailwind' => [Profile::Tailwind, [
                'resources/css/theme.overrides.css',
                'resources/css/theme.tailwind.generated.css',
                'resources/css/theme.tokens.generated.css',
                'theme/tokens.json',
            ], [
                'resources/css/tailwind.css',
            ], []],
            'bootstrap' => [Profile::Bootstrap, [
                'resources/css/theme.bootstrap.generated.css',
                'resources/css/theme.overrides.css',
                'resources/css/theme.tokens.generated.css',
                'resources/scss/_sahil-theme.generated.scss',
                'resources/scss/_sahil-theme.overrides.scss',
                'theme/tokens.json',
            ], [
                'resources/css/bootstrap.css',
            ], []],
            'both' => [Profile::Both, [
                'resources/css/theme.bootstrap.generated.css',
                'resources/css/theme.overrides.css',
                'resources/css/theme.tailwind.generated.css',
                'resources/css/theme.tokens.generated.css',
                'resources/scss/_sahil-theme.generated.scss',
                'resources/scss/_sahil-theme.overrides.scss',
                'theme/tokens.json',
            ], [
                'resources/css/bootstrap.css',
                'resources/css/tailwind.css',
            ], []],
            'none' => [Profile::None, [
                'resources/css/theme.overrides.css',
                'resources/css/theme.tokens.generated.css',
                'theme/tokens.json',
            ], [
                'resources/css/app.css',
            ], []],
        ];
    }

    /**
     * @param list<string> $created
     * @param list<string> $entries
     * @param list<string> $notCreated
     */
    #[DataProvider('profileFiles')]
    public function test_install_creates_only_the_profile_theme_files(
        Profile $profile,
        array $created,
        array $entries,
        array $notCreated,
    ): void {
        $this->seedProfile($profile);

        $report = (new ThemeInstaller())->install($this->projectPath, $profile);

        self::assertSame('sahil-neutral', $report->theme);
        self::assertTrue($report->sourceCreated);
        self::assertSame($created, $report->created);

        foreach ($created as $file) {
            self::assertFileExists($this->projectPath.'/'.$file);
        }

        foreach ($notCreated as $file) {
            self::assertFileDoesNotExist($this->projectPath.'/'.$file);
        }

        foreach ($entries as $entry) {
            $css = $this->read($entry);
            self::assertStringContainsString('/* @sahil-theme:start */', $css);
            self::assertStringContainsString('/* @sahil-theme:end */', $css);
            self::assertStringContainsString('theme.tokens.generated.css', $css);
            self::assertStringContainsString('theme.overrides.css', $css);
        }
    }

    public function test_tailwind_entry_imports_theme_after_tailwindcss(): void
    {
        $this->seedProfile(Profile::Tailwind);
        (new ThemeInstaller())->install($this->projectPath, Profile::Tailwind);

        $css = $this->read('resources/css/tailwind.css');

        self::assertLessThan(
            strpos($css, '@import "./theme.tokens.generated.css";'),
            strpos($css, '@import "tailwindcss";')
        );
        self::assertStringContainsString('@import "./theme.tailwind.generated.css";', $css);
        self::assertStringNotContainsString('theme.bootstrap.generated.css', $css);
    }

    public function test_regeneration_updates_generated_output_and_preserves_user_files(): void
    {
        $this->seedProfile(Profile::Tailwind);

        $installer = new ThemeInstaller();
        $installer->install($this->projectPath, Profile::Tailwind);

        $override = "/* mine */\n:root { --sahil-color-primary: #0f766e; }\n";
        $this->write('resources/css/theme.overrides.css', $override);
        $source = $this->themeJson(name: 'brand-theme', primary: '#0f766e');
        $this->write('theme/tokens.json', $source);

        $report = $installer->install($this->projectPath, Profile::Tailwind);

        self::assertSame($override, $this->read('resources/css/theme.overrides.css'));
        self::assertSame($source, $this->read('theme/tokens.json'));
        self::assertSame('brand-theme', $report->theme);
        self::assertStringContainsString('--sahil-color-primary: #0f766e;', $this->read('resources/css/theme.tokens.generated.css'));
    }

    public function test_install_is_idempotent_and_does_not_duplicate_the_marker(): void
    {
        $this->seedProfile(Profile::Tailwind);
        $installer = new ThemeInstaller();

        $installer->install($this->projectPath, Profile::Tailwind);
        $first = $this->read('resources/css/tailwind.css');
        $report = $installer->install($this->projectPath, Profile::Tailwind);

        self::assertSame($first, $this->read('resources/css/tailwind.css'));
        self::assertSame(1, substr_count($first, '/* @sahil-theme:start */'));
        self::assertContains('resources/css/tailwind.css', $report->skipped);
    }

    public function test_dry_run_reports_the_plan_without_writing(): void
    {
        $this->seedProfile(Profile::None);
        $before = $this->read('resources/css/app.css');

        $report = (new ThemeInstaller())->install($this->projectPath, Profile::None, dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertContains('theme/tokens.json', $report->created);
        self::assertContains('resources/css/theme.tokens.generated.css', $report->created);
        self::assertFileDoesNotExist($this->projectPath.'/theme/tokens.json');
        self::assertFileDoesNotExist($this->projectPath.'/resources/css/theme.tokens.generated.css');
        self::assertSame($before, $this->read('resources/css/app.css'));
    }

    public function test_custom_source_is_read_without_creating_the_default_file(): void
    {
        $this->seedProfile(Profile::Tailwind);
        $this->write('theme/brand.json', $this->themeJson(name: 'brand-theme', primary: '#0f766e'));

        $report = (new ThemeInstaller())->install(
            $this->projectPath,
            Profile::Tailwind,
            'theme/brand.json'
        );

        self::assertSame('theme/brand.json', $report->source);
        self::assertFalse($report->sourceCreated);
        self::assertFileDoesNotExist($this->projectPath.'/theme/tokens.json');
        self::assertStringContainsString('#0f766e', $this->read('resources/css/theme.tokens.generated.css'));
    }

    public function test_missing_entry_file_fails_before_writing_anything(): void
    {
        try {
            (new ThemeInstaller())->install($this->projectPath, Profile::Tailwind);
            self::fail('Expected a missing entry error.');
        } catch (ThemeException $error) {
            self::assertStringContainsString('Run `bin/sahil ui:install tailwind` first', $error->getMessage());
        }

        self::assertFileDoesNotExist($this->projectPath.'/theme/tokens.json');
        self::assertFileDoesNotExist($this->projectPath.'/resources/css/theme.tokens.generated.css');
    }

    public function test_invalid_theme_source_fails_before_writing_generated_files(): void
    {
        $this->seedProfile(Profile::Tailwind);
        $this->write('theme/tokens.json', '{"colors":');

        try {
            (new ThemeInstaller())->install($this->projectPath, Profile::Tailwind);
            self::fail('Expected invalid JSON to fail.');
        } catch (ThemeException $error) {
            self::assertStringContainsString('not valid JSON', $error->getMessage());
        }

        self::assertFileDoesNotExist($this->projectPath.'/resources/css/theme.tokens.generated.css');
    }

    public function test_missing_custom_source_fails(): void
    {
        $this->seedProfile(Profile::Tailwind);

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('was not found');

        (new ThemeInstaller())->install($this->projectPath, Profile::Tailwind, 'theme/missing.json');
    }

    private function seedProfile(Profile $profile): void
    {
        foreach (ProfilePlan::build($profile)->files as $relative => $content) {
            $this->write($relative, $content);
        }
    }

    private function themeJson(string $name, string $primary): string
    {
        $data = ThemeTokens::sample()->toArray();
        $data['name'] = $name;
        $data['colors']['primary'] = ['light' => $primary, 'dark' => $primary];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
