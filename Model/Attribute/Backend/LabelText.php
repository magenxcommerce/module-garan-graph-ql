<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Attribute\Backend;

use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magenx\GaranGraphQl\Model\Garan\LabelValidator;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * GARAN brand and model identifier: trimmed, and rejected when the text does not fit the label (alone or combined).
 */
class LabelText extends AbstractBackend implements ResetAfterRequestInterface
{
    use ResetsBackendState;

    public function __construct(
        private readonly LabelValidator $labelValidator
    ) {
    }

    /**
     * @param DataObject $object
     * @throws LocalizedException
     */
    public function validate($object)
    {
        parent::validate($object);
        $this->assertFits($object);

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
            $value = $this->assertFits($object);
            $object->setData($code, $value === '' ? null : $value);
        }

        return $this;
    }

    /**
     * @return string the trimmed value of this attribute
     * @throws LocalizedException
     */
    private function assertFits(DataObject $object): string
    {
        $code = (string) $this->getAttribute()->getAttributeCode();
        $value = $this->labelValidator->normalizeText($object->getData($code));
        $brand = $code === Attributes::BRAND
            ? $value
            : $this->labelValidator->normalizeText($object->getData(Attributes::BRAND));
        $model = $code === Attributes::MODEL_IDENTIFIER
            ? $value
            : $this->labelValidator->normalizeText($object->getData(Attributes::MODEL_IDENTIFIER));

        if ($value !== '' && !$this->labelValidator->textFits($brand, $model)) {
            throw new LocalizedException(
                __(
                    'The GARAN brand "%1" and model identifier "%2" are too long for the label. '
                    . 'Please shorten them.',
                    $brand,
                    $model
                )
            );
        }

        return $value;
    }
}
