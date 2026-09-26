<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Source;

use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magento\Framework\Data\OptionSourceInterface;

class Language implements OptionSourceInterface
{
    public function __construct(
        private readonly LanguageRegistry $languageRegistry
    ) {
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Automatic (from store locale)')]];
        foreach ($this->languageRegistry->getCodes() as $code) {
            $options[] = [
                'value' => $code,
                'label' => sprintf('%s (%s)', $this->languageRegistry->getName($code), strtoupper($code)),
            ];
        }

        return $options;
    }
}
