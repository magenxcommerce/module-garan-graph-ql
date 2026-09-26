<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Attribute\Backend;

use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * GARAN duration: accepts locale input ("4,5") and stores the parsed float; empty means "no label".
 */
class Duration extends AbstractBackend
{
    public function __construct(
        private readonly DurationParser $durationParser
    ) {
    }

    /**
     * @param DataObject $object
     * @throws LocalizedException
     */
    public function validate($object)
    {
        parent::validate($object);
        $this->parseValue($object);

        return true;
    }

    /**
     * @param DataObject $object
     * @throws LocalizedException
     */
    public function beforeSave($object)
    {
        parent::beforeSave($object);
        $code = (string) $this->getAttribute()->getAttributeCode();
        if ($object->hasData($code)) {
            $object->setData($code, $this->parseValue($object));
        }

        return $this;
    }

    /**
     * @throws LocalizedException
     */
    private function parseValue(DataObject $object): ?float
    {
        $raw = $object->getData((string) $this->getAttribute()->getAttributeCode());
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $duration = $this->durationParser->parse($raw);
        if ($duration === null) {
            throw new LocalizedException(
                __(
                    'The GARAN guarantee duration "%1" is invalid. Enter whole or half years greater than 2 '
                    . 'and at most 99, e.g. 3 or 4,5.',
                    is_scalar($raw) ? (string) $raw : ''
                )
            );
        }

        return $duration;
    }
}
