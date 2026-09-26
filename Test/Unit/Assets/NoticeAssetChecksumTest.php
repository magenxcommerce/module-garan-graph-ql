<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Assets;

use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The official EU notice files must be shipped unmodified (EU guidelines §2.1.4, AC-N5).
 */
class NoticeAssetChecksumTest extends TestCase
{
    private const NOTICE_DIR = __DIR__ . '/../../../view/base/web/notice/';

    /**
     * @dataProvider checksumProvider
     */
    public function testNoticeFileMatchesRecordedChecksum(string $file, string $expectedHash): void
    {
        $path = self::NOTICE_DIR . $file;

        self::assertFileExists($path);
        self::assertSame($expectedHash, hash_file('sha256', $path), sprintf('%s was modified.', $file));
    }

    public function testEverySupportedLanguageHasSvgAndPngWithChecksum(): void
    {
        $recorded = array_column(self::checksumProvider(), 0);

        foreach ((new LanguageRegistry())->getCodes() as $code) {
            self::assertContains($code . '.svg', $recorded);
            self::assertContains($code . '.png', $recorded);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function checksumProvider(): array
    {
        $rows = [];
        foreach (file(self::NOTICE_DIR . 'CHECKSUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$hash, $file] = preg_split('/\s+/', trim($line), 2);
            $rows[$file] = [$file, $hash];
        }

        return $rows;
    }
}
