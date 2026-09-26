<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Email;

use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Email\TermsDocument;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TermsDocumentTest extends TestCase
{
    private const STORE_ID = 3;
    private const STORED_VALUE = 'stores/3/terms.pdf';
    private const PATH = 'magenx_garan/terms/stores/3/terms.pdf';
    private const CONTENT = '%PDF-1.7 binary';
    private const DISPLAY_NAME = 'Garantiebedingungen.pdf';

    private Config&MockObject $config;
    private ReadInterface&MockObject $mediaDirectory;
    private LoggerInterface&MockObject $logger;
    private TermsDocument $termsDocument;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mediaDirectory = $this->createMock(ReadInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::MEDIA)->willReturn($this->mediaDirectory);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->termsDocument = new TermsDocument($this->config, $filesystem, $this->logger);
    }

    public function testStoredScopeValueIsResolvedBelowTheUploadDirectory(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->givenFile(self::PATH, strlen(self::CONTENT));
        $this->logger->expects($this->never())->method('warning');

        $document = $this->termsDocument->resolve(self::STORE_ID);

        $this->assertNotNull($document);
        $this->assertSame(self::DISPLAY_NAME, $document->getName());
        $this->assertSame(self::CONTENT, $document->getContent());
        $this->assertSame('application/pdf', $document->getMimeType());
    }

    public function testBareFileNameIsResolvedBelowTheUploadDirectory(): void
    {
        $this->givenConfig('garantiebedingungen-test.pdf', self::DISPLAY_NAME);
        $this->givenFile('magenx_garan/terms/garantiebedingungen-test.pdf', strlen(self::CONTENT));

        $document = $this->termsDocument->resolve(self::STORE_ID);

        $this->assertNotNull($document);
        $this->assertSame(self::CONTENT, $document->getContent());
        $this->assertSame('application/pdf', $document->getMimeType());
    }

    public function testValueContainingTheUploadDirectoryIsNotPrefixedTwice(): void
    {
        $this->givenConfig('magenx_garan/terms/default/terms.pdf', self::DISPLAY_NAME);
        $this->givenFile('magenx_garan/terms/default/terms.pdf', strlen(self::CONTENT));

        $this->assertNotNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testLeadingSlashesAreIgnored(): void
    {
        $this->givenConfig('/default/terms.pdf', self::DISPLAY_NAME);
        $this->givenFile('magenx_garan/terms/default/terms.pdf', strlen(self::CONTENT));

        $this->assertNotNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testUploadedFileNameIsUsedWhenNoNameIsConfigured(): void
    {
        $this->givenConfig(self::STORED_VALUE, '');
        $this->givenFile(self::PATH, strlen(self::CONTENT));

        $document = $this->termsDocument->resolve(self::STORE_ID);

        $this->assertNotNull($document);
        $this->assertSame('terms.pdf', $document->getName());
    }

    public function testConfiguredNameWithoutExtensionInheritsTheUploadedOne(): void
    {
        $this->givenConfig(self::STORED_VALUE, 'Garantiebedingungen');
        $this->givenFile(self::PATH, strlen(self::CONTENT));

        $document = $this->termsDocument->resolve(self::STORE_ID);

        $this->assertNotNull($document);
        $this->assertSame('Garantiebedingungen.pdf', $document->getName());
    }

    public function testUnknownExtensionFallsBackToTheGenericMimeType(): void
    {
        $this->givenConfig('default/terms.bin', 'terms.bin');
        $this->givenFile('magenx_garan/terms/default/terms.bin', strlen(self::CONTENT));

        $document = $this->termsDocument->resolve(self::STORE_ID);

        $this->assertNotNull($document);
        $this->assertSame('application/octet-stream', $document->getMimeType());
    }

    public function testMissingConfigurationIsLoggedAndTouchesNoFile(): void
    {
        $this->givenConfig('', self::DISPLAY_NAME);
        $this->mediaDirectory->expects($this->never())->method('isExist');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testPathTraversalIsRefused(): void
    {
        $this->givenConfig('../../app/etc/env.php', self::DISPLAY_NAME);
        $this->mediaDirectory->expects($this->never())->method('isExist');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testMissingFileIsLogged(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->mediaDirectory->method('isExist')->willReturn(false);
        $this->mediaDirectory->expects($this->never())->method('readFile');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testUnreadableFileIsLogged(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->mediaDirectory->method('isExist')->willReturn(true);
        $this->mediaDirectory->method('isFile')->willReturn(true);
        $this->mediaDirectory->method('isReadable')->willReturn(false);
        $this->mediaDirectory->expects($this->never())->method('readFile');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testEmptyFileIsLogged(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->givenFile(self::PATH, 0);
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testTooLargeFileIsLogged(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->givenFile(self::PATH, TermsDocument::MAX_FILE_SIZE + 1);
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testFileAtTheSizeLimitIsStillAttached(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->givenFile(self::PATH, TermsDocument::MAX_FILE_SIZE);

        $this->assertNotNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testUnreadableContentIsLogged(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->givenFile(self::PATH, strlen(self::CONTENT), '');
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    public function testFilesystemErrorIsCaughtAndLogged(): void
    {
        $this->givenConfig(self::STORED_VALUE, self::DISPLAY_NAME);
        $this->mediaDirectory->method('isExist')->willThrowException(new RuntimeException('media gone'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->termsDocument->resolve(self::STORE_ID));
    }

    private function givenConfig(string $storedValue, string $displayName): void
    {
        $this->config->method('getTermsFileValue')->with(self::STORE_ID)->willReturn($storedValue);
        $this->config->method('getTermsAttachmentFilename')->with(self::STORE_ID)->willReturn($displayName);
    }

    private function givenFile(string $path, int $size, string $content = self::CONTENT): void
    {
        $this->mediaDirectory->method('isExist')->with($path)->willReturn(true);
        $this->mediaDirectory->method('isFile')->with($path)->willReturn(true);
        $this->mediaDirectory->method('isReadable')->with($path)->willReturn(true);
        $this->mediaDirectory->method('stat')->with($path)->willReturn(['size' => $size]);
        $this->mediaDirectory->method('readFile')->with($path)->willReturn($content);
    }
}
