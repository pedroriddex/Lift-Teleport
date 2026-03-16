<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Import;

use LiftTeleport\Import\DatabaseImporter;
use PHPUnit\Framework\TestCase;

final class DatabaseImporterTest extends TestCase
{
    public function testExtractStatementsHandlesSemicolonsAndComments(): void
    {
        $importer = $this->makeImporterWithoutConstructor();
        $extract = \Closure::bind(function (string &$buffer): array {
            return $this->extractStatements($buffer);
        }, $importer, DatabaseImporter::class);

        $buffer = <<<SQL
            -- Header comment;
            INSERT INTO `wp_options` (`option_value`) VALUES ('semi;colon');
            # Inline comment;
            INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('a', 'b');
            /* block;comment; */
            SQL;

        $statements = $extract($buffer);

        self::assertCount(2, $statements);
        self::assertStringContainsString("VALUES ('semi;colon');", $statements[0]);
        self::assertStringContainsString("VALUES ('a', 'b');", $statements[1]);
        self::assertSame("/* block;comment; */", trim($buffer));
    }

    public function testExtractStatementsKeepsPartialTailInBuffer(): void
    {
        $importer = $this->makeImporterWithoutConstructor();
        $extract = \Closure::bind(function (string &$buffer): array {
            return $this->extractStatements($buffer);
        }, $importer, DatabaseImporter::class);

        $buffer = "INSERT INTO `wp_posts` (`post_title`) VALUES ('ok');\nINSERT INTO `wp_posts` (`post_title`) VALUES ('partial'";
        $statements = $extract($buffer);

        self::assertCount(1, $statements);
        self::assertStringContainsString("VALUES ('ok');", $statements[0]);
        self::assertStringContainsString("VALUES ('partial'", $buffer);
    }

    public function testIsCommentOnlyAllowsCommentPlusSqlStatement(): void
    {
        $importer = $this->makeImporterWithoutConstructor();
        $isCommentOnly = \Closure::bind(function (string $query): bool {
            return $this->isCommentOnly($query);
        }, $importer, DatabaseImporter::class);

        $query = "-- Lift header\n-- Generated\nDROP TABLE IF EXISTS `wp_commentmeta`;";
        self::assertFalse($isCommentOnly($query));
        self::assertTrue($isCommentOnly("-- just a comment"));
    }

    public function testShouldSkipInternalStatementForRuntimeTables(): void
    {
        $GLOBALS['wpdb'] = new \wpdb();
        $importer = new DatabaseImporter();
        $shouldSkip = \Closure::bind(function (string $query, string $sourcePrefix, string $destPrefix): bool {
            return $this->shouldSkipInternalStatement($query, $sourcePrefix, $destPrefix);
        }, $importer, DatabaseImporter::class);

        self::assertTrue($shouldSkip('CREATE TABLE `wp_lift_jobs` (`id` bigint);', 'wp_', 'wp_'));
        self::assertTrue($shouldSkip('INSERT INTO `wp_lift_job_events` VALUES (1);', 'wp_', 'wp_'));
        self::assertFalse($shouldSkip('CREATE TABLE `wp_posts` (`ID` bigint);', 'wp_', 'wp_'));
        self::assertFalse($shouldSkip('INSERT INTO `wp_options` (`option_name`) VALUES (\'siteurl\');', 'wp_', 'wp_'));
    }

    private function makeImporterWithoutConstructor(): DatabaseImporter
    {
        $reflection = new \ReflectionClass(DatabaseImporter::class);
        /** @var DatabaseImporter $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        return $instance;
    }
}
