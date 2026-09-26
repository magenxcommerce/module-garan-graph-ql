<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Render;

use Magenx\GaranGraphQl\Model\Asset\MediaAssets;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magenx\GaranGraphQl\Model\Render\NoticeRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NoticeRendererTest extends TestCase
{
    private Config&MockObject $config;
    private MediaAssets&MockObject $mediaAssets;
    private NoticeRenderer $renderer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mediaAssets = $this->createMock(MediaAssets::class);
        $this->renderer = new NoticeRenderer($this->config, new LanguageRegistry(), $this->mediaAssets);
    }

    public function testSvgUrlUsesMediaNoticeOfStoreLanguage(): void
    {
        $this->config->expects($this->once())->method('getLanguageCode')->with(3)->willReturn('de');
        $this->mediaAssets->expects($this->once())
            ->method('getUrl')
            ->with('notice/de.svg', 3)
            ->willReturn('https://shop.test/media/garan/notice/de.svg');

        $this->assertSame('https://shop.test/media/garan/notice/de.svg', $this->renderer->getSvgUrl(3));
    }

    public function testPngUrlUsesMediaNoticeOfStoreForEmails(): void
    {
        $this->config->method('getLanguageCode')->with(7)->willReturn('en');
        $this->mediaAssets->expects($this->once())
            ->method('getUrl')
            ->with('notice/en.png', 7)
            ->willReturn('https://shop.test/media/garan/notice/en.png');

        $this->assertSame('https://shop.test/media/garan/notice/en.png', $this->renderer->getPngUrl(7));
    }

    public function testPngSourceFileIsInMedia(): void
    {
        $this->config->method('getLanguageCode')->with(7)->willReturn('fr');
        $this->mediaAssets->expects($this->once())
            ->method('getAbsolutePath')
            ->with('notice/fr.png')
            ->willReturn('/var/www/pub/media/garan/notice/fr.png');

        $this->assertSame('/var/www/pub/media/garan/notice/fr.png', $this->renderer->getPngSourceFile(7));
    }

    public function testLinkMatchesQrTargetOfLanguage(): void
    {
        $this->config->method('getLanguageCode')->willReturn('de');

        $this->assertSame('https://europa.eu/youreurope/garantien', $this->renderer->getLinkUrl());
        $this->assertSame('europa.eu/youreurope/garantien', $this->renderer->getLinkLabel());
    }

    public function testDefaultAltTextContainsDisplayUrl(): void
    {
        $this->config->method('getLanguageCode')->willReturn('en');

        $altText = $this->renderer->getAltText();

        $this->assertStringStartsWith('EU legal guarantee notice:', $altText);
        $this->assertStringEndsWith('More information: europa.eu/youreurope/guarantees', $altText);
    }
}
