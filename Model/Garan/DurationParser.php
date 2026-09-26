<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

/**
 * Parses a GARAN guarantee duration: whole or half years, more than 2 and at most 99 (EU guidelines p. 21-22).
 */
class DurationParser
{
    /**
     * Durations are compared in millionths of a year, the precision of the EAV decimal(20,6) column.
     */
    private const MICRO_YEARS_PER_HALF_YEAR = 500000;
    private const MIN_HALF_YEARS_EXCLUSIVE = 4;
    private const MAX_HALF_YEARS = 198;
    /**
     * Guards the integer conversion; everything from here on is invalid anyway.
     */
    private const OUT_OF_RANGE_YEARS = 1000.0;
    private const NUMBER_PATTERN = '/^\d{1,3}(?:[.,]\d{1,12})?$/';

    /**
     * Accepts "4,5", "4.5", 4.5, " 3 " or the stored "4.500000"; null for anything that is not a valid duration.
     *
     * @param mixed $raw
     * @return float|null
     */
    public function parse(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            $value = (float) $raw;
        } elseif (is_string($raw) && preg_match(self::NUMBER_PATTERN, trim($raw)) === 1) {
            $value = (float) str_replace(',', '.', trim($raw));
        } else {
            return null;
        }

        if (!is_finite($value) || $value <= 0.0 || $value >= self::OUT_OF_RANGE_YEARS) {
            return null;
        }

        $microYears = (int) round($value * 1000000);
        if ($microYears % self::MICRO_YEARS_PER_HALF_YEAR !== 0) {
            return null;
        }

        $halfYears = intdiv($microYears, self::MICRO_YEARS_PER_HALF_YEAR);
        if ($halfYears <= self::MIN_HALF_YEARS_EXCLUSIVE || $halfYears > self::MAX_HALF_YEARS) {
            return null;
        }

        return $halfYears / 2;
    }

    /**
     * Duration as printed on the label: "5" or "4,5"; same output as GaranLabelData::getFormattedDuration().
     */
    public function format(float $years): string
    {
        if (fmod($years, 1.0) === 0.0) {
            return (string) (int) $years;
        }

        return number_format($years, 1, ',', '');
    }
}
