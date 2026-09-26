<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Render;

use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magenx\GaranGraphQl\Model\Render\NoticeRenderer;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NoticeRendererTest extends TestCase
{
    private Config&MockObject $config;
    private AssetRepository&MockObject $assetRepository;
    private NoticeRenderer $renderer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->renderer = new NoticeRenderer($this->config, new LanguageRegistry(), $this->assetRepository);
    }

    public function testSvgUrlUsesFrontendNoticeAssetOfStoreLanguage(): void
    {
        $this->config->expects($this->once())->method('getLanguageCode')->with(3)->willReturn('de');
        $this->assetRepository->expects($this->once())
            ->method('getUrlWithParams')
            ->with('Magenx_GaranGraphQl::notice/de.svg', ['area' => 'frontend', '_secure' => true])
            ->willReturn('https://shop.test/static/frontend/Magenx_GaranGraphQl/notice/de.svg');

        $this->assertSame(
            'https://shop.test/static/frontend/Magenx_GaranGraphQl/notice/de.svg',
            $this->renderer->getSvgUrl(3)
        );
    }

    public function testPngUrlUsesFrontendAreaAndSecureUrlForEmails(): void
    {
        $this->config->method('getLanguageCode')->with(7)->willReturn('en');
        $this->assetRepository->expects($this->once())
            ->method('getUrlWithParams')
            ->with('Magenx_GaranGraphQl::notice/en.png', ['area' => 'frontend', '_secure' => true])
            ->willReturn('https://shop.test/static/frontend/Magenx_GaranGraphQl/notice/en.png');

        $this->assertSame(
            'https://shop.test/static/frontend/Magenx_GaranGraphQl/notice/en.png',
            $this->renderer->getPngUrl(7)
        );
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
