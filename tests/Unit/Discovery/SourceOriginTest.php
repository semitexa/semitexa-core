<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\SourceOrigin;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Direct tests for {@see SourceOrigin}, extracted from AttributeDiscovery in
 * ep-slay-attribute-discovery (tk-ad-source-origin).
 *
 * The ranking these methods produce decides which class wins an override
 * contest at boot, so the ordering between tiers is the contract — not the
 * absolute numbers. `override_ranking_is_outermost_wins` pins the ordering
 * rather than the constants, so retuning a value cannot silently invert a
 * precedence rule.
 */
final class SourceOriginTest extends TestCase
{
    private const ROOT = '/tmp/semitexa-source-origin-fixture';

    protected function setUp(): void
    {
        ProjectRoot::reset();
        $property = new \ReflectionProperty(ProjectRoot::class, 'root');
        $property->setAccessible(true);
        $property->setValue(null, self::ROOT);
    }

    protected function tearDown(): void
    {
        ProjectRoot::reset();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function fileOrigins(): array
    {
        return [
            'installed module' => [self::ROOT . '/src/modules/Blog/src/X.php', SourceOrigin::PRIORITY_MODULE],
            'project src' => [self::ROOT . '/src/Application/X.php', SourceOrigin::PRIORITY_PROJECT],
            'workspace package' => ['/opt/app/packages/semitexa-core/src/X.php', SourceOrigin::PRIORITY_PACKAGE],
            'vendor' => ['/opt/app/vendor/semitexa/core/src/X.php', SourceOrigin::PRIORITY_VENDOR],
            'no file' => ['', SourceOrigin::PRIORITY_UNKNOWN],
        ];
    }

    #[Test]
    #[DataProvider('fileOrigins')]
    public function priority_reflects_where_the_file_lives(string $file, int $expected): void
    {
        self::assertSame($expected, (new SourceOrigin())->priorityForFile($file));
    }

    #[Test]
    public function override_ranking_is_outermost_wins(): void
    {
        // The ordering IS the policy: a module overrides the project, which
        // overrides a workspace package, which overrides vendor. Pinning the
        // order (not the values) keeps a retune from inverting a rule silently.
        $origin = new SourceOrigin();
        $ranked = [
            $origin->priorityForFile(self::ROOT . '/src/modules/Blog/src/X.php'),
            $origin->priorityForFile(self::ROOT . '/src/Application/X.php'),
            $origin->priorityForFile('/opt/app/packages/semitexa-core/src/X.php'),
            $origin->priorityForFile('/opt/app/vendor/semitexa/core/src/X.php'),
            $origin->priorityForFile(''),
        ];

        $sorted = $ranked;
        rsort($sorted);
        self::assertSame($sorted, $ranked, 'Origin tiers must rank strictly outermost-wins.');
        self::assertSame($ranked, array_unique($ranked), 'No two origin tiers may tie.');
    }

    #[Test]
    public function a_module_under_project_src_outranks_project_src_itself(): void
    {
        // src/modules/ is a prefix match on src/, so the module check must be
        // evaluated first. This is the one ordering bug the implementation can
        // regress into without any test noticing.
        $origin = new SourceOrigin();

        self::assertGreaterThan(
            $origin->priorityForFile(self::ROOT . '/src/Application/X.php'),
            $origin->priorityForFile(self::ROOT . '/src/modules/Blog/src/X.php'),
        );
    }

    #[Test]
    public function project_files_are_recognised_by_root_prefix(): void
    {
        $origin = new SourceOrigin();

        self::assertTrue($origin->isProjectFile(self::ROOT . '/src/Application/X.php'));
        self::assertTrue($origin->isProjectFile(self::ROOT . '/src/modules/Blog/src/X.php'));
        self::assertFalse($origin->isProjectFile('/somewhere/else/src/X.php'));
        self::assertFalse($origin->isProjectFile(''));
    }

    #[Test]
    public function a_sibling_directory_sharing_the_root_prefix_is_not_project_code(): void
    {
        // ProjectRoot . '/src/' — not just ProjectRoot — is the boundary, so a
        // path like <root>-backup/src or <root>/srcfoo must not be admitted.
        $origin = new SourceOrigin();

        self::assertFalse($origin->isProjectFile(self::ROOT . '-backup/src/X.php'));
        self::assertFalse($origin->isProjectFile(self::ROOT . '/srcfoo/X.php'));
    }

    #[Test]
    public function an_unloadable_class_is_answered_not_project_rather_than_thrown(): void
    {
        // A single broken class must never abort a boot-wide scan; the
        // conservative answer is "no project authority".
        self::assertFalse(
            (new SourceOrigin())->isProjectClass('Totally\\Missing\\Class', 'payload'),
        );
    }

    #[Test]
    public function a_real_class_is_classified_by_its_file(): void
    {
        // SourceOrigin itself lives in packages/, not under the fixture root.
        self::assertFalse((new SourceOrigin())->isProjectClass(SourceOrigin::class, 'resource'));
    }
}
