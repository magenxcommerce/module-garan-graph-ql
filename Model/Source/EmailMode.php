<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How a graphic reaches the order confirmation. An email cannot open a dialog, so the storefront modes do not apply;
 * what it can do is show the image in the body or carry it as a file.
 */
class EmailMode implements OptionSourceInterface
{
    public const NO = 'no';
    public const INLINE = 'inline';
    public const ATTACHMENT = 'attachment';
    public const MODES = [self::NO, self::INLINE, self::ATTACHMENT];

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NO, 'label' => __('No')],
            ['value' => self::INLINE, 'label' => __('Inline in the email')],
            ['value' => self::ATTACHMENT, 'label' => __('As a file attachment')],
        ];
    }
}
