<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Attribute\Backend;

use Magenx\GaranGraphQl\Model\Garan\LabelValidator;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * GARAN guarantee terms URL: empty or an absolute http(s) URL.
 */
class TermsUrl extends AbstractBackend implements ResetAfterRequestInterface
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
        $this->assertValid($object);

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
            $value = $this->assertValid($object);
            $object->setData($code, $value === '' ? null : $value);
        }

        return $this;
    }

    /**
     * @return string the trimmed URL
     * @throws LocalizedException
     */
    private function assertValid(DataObject $object): string
    {
        $url = $this->labelValidator->normalizeText(
            $object->getData((string) $this->getAttribute()->getAttributeCode())
        );
        if ($url !== '' && !$this->labelValidator->isValidTermsUrl($url)) {
            throw new LocalizedException(
                __('The GARAN guarantee terms URL "%1" is invalid. Enter a full http:// or https:// URL.', $url)
            );
        }

        return $url;
    }
}
