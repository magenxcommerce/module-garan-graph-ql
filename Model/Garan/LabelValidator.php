<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

/**
 * Validation rules of the GARAN label fields, shared by the resolver (render time) and the audit command.
 * Data can bypass the attribute backend models (mass update, direct DB imports), so every read is validated.
 */
class LabelValidator
{
    public const REASON_MISSING_BRAND = 'missing_brand';
    public const REASON_MISSING_MODEL_IDENTIFIER = 'missing_model_identifier';
    public const REASON_MISSING_DURATION = 'missing_duration';
    public const REASON_MISSING_TERMS_URL = 'missing_terms_url';
    public const REASON_INVALID_DURATION = 'invalid_duration';
    /**
     * Legally valid duration that is too wide for the label at the official font size (e.g. most half years).
     */
    public const REASON_DURATION_DOES_NOT_FIT = 'duration_does_not_fit';
    public const REASON_INVALID_TERMS_URL = 'invalid_terms_url';
    public const REASON_TOO_LONG = 'too_long';

    private const ALLOWED_URL_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly DurationParser $durationParser,
        private readonly FieldFitChecker $fieldFitChecker
    ) {
    }

    /**
     * Reason codes why the raw attribute values do not form a valid label; an empty list means valid.
     *
     * @return list<string>
     */
    public function getViolations(mixed $brand, mixed $modelIdentifier, mixed $duration, mixed $termsUrl): array
    {
        $brand = $this->normalizeText($brand);
        $modelIdentifier = $this->normalizeText($modelIdentifier);
        $termsUrl = $this->normalizeText($termsUrl);
        $violations = [];

        if ($brand === '') {
            $violations[] = self::REASON_MISSING_BRAND;
        }
        if ($modelIdentifier === '') {
            $violations[] = self::REASON_MISSING_MODEL_IDENTIFIER;
        }
        $years = $this->durationParser->parse($duration);
        if ($this->normalizeText($duration) === '') {
            $violations[] = self::REASON_MISSING_DURATION;
        } elseif ($years === null) {
            $violations[] = self::REASON_INVALID_DURATION;
        } elseif (!$this->fieldFitChecker->fitsDuration($this->durationParser->format($years))) {
            $violations[] = self::REASON_DURATION_DOES_NOT_FIT;
        }
        if ($termsUrl === '') {
            $violations[] = self::REASON_MISSING_TERMS_URL;
        } elseif (!$this->isValidTermsUrl($termsUrl)) {
            $violations[] = self::REASON_INVALID_TERMS_URL;
        }
        if (!$this->textFits($brand, $modelIdentifier)) {
            $violations[] = self::REASON_TOO_LONG;
        }

        return $violations;
    }

    /**
     * Brand and model identifier fit the label, each on its own and together; empty values always fit.
     */
    public function textFits(string $brand, string $modelIdentifier): bool
    {
        if ($brand !== '' && !$this->fieldFitChecker->fitsBrand($brand)) {
            return false;
        }
        if ($modelIdentifier !== '' && !$this->fieldFitChecker->fitsModelIdentifier($modelIdentifier)) {
            return false;
        }

        return $brand === ''
            || $modelIdentifier === ''
            || $this->fieldFitChecker->fitsBrandAndModel($brand, $modelIdentifier);
    }

    /**
     * Absolute http(s) URL with a host.
     */
    public function isValidTermsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // phpcs:disable Magento2.Functions.DiscouragedFunction -- validation only, the URL is never fetched.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        // phpcs:enable Magento2.Functions.DiscouragedFunction

        return in_array($scheme, self::ALLOWED_URL_SCHEMES, true) && $host !== '';
    }

    public function normalizeText(mixed $value): string
    {
        return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
    }
}
