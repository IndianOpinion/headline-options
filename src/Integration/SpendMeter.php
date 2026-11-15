<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Integration;

use IndianOpinion\HeadlineOptions\Exception\MeterRefusedException;
use IndianOpinion\HeadlineOptions\Logger;

/**
 * Soft integration with spend-meter through its published hooks (contract 1).
 * The meter can only deny further: this plugin's own caps and kill switch run
 * first. A missing meter or a newer contract is treated as no meter.
 */
final class SpendMeter
{
    public const CONTRACT = 1;
    public const PURPOSE = 'headline-options.generate';
    public const CALLER = 'headline-options';

    /** Contract version present, or null. */
    public static function version(): ?int
    {
        if (!defined('SPEND_METER_API')) {
            return null;
        }
        $v = constant('SPEND_METER_API');

        return is_numeric($v) ? (int) $v : null;
    }

    public static function active(): bool
    {
        return self::version() === self::CONTRACT && function_exists('apply_filters');
    }

    /** True when a meter with a newer contract is present (shown as a notice). */
    public static function newer(): bool
    {
        $v = self::version();

        return $v !== null && $v > self::CONTRACT;
    }

    /**
     * Ask before the call. Returns the reservation id (or null) when allowed.
     *
     * @throws MeterRefusedException when the decision is anything but `allowed === true`
     */
    public function preSpend(string $provider, string $model, int $inputTokens, int $maxOutputTokens): ?string
    {
        if (!self::active()) {
            return null;
        }
        try {
            $decision = apply_filters('spend_meter_pre_spend', null, [
                'purpose' => self::PURPOSE,
                'caller' => self::CALLER,
                'provider' => $provider,
                'model' => $model,
                'est_input_tokens' => $inputTokens,
                'max_output_tokens' => $maxOutputTokens,
            ]);
        } catch (\Throwable $e) {
            Logger::log('error', 'spend-meter pre-spend filter threw; treated as a deny', ['exception' => get_class($e)]);

            throw new MeterRefusedException('The site spend meter could not be asked, so the call was not made.', 'filter_error');
        }
        if ($decision === null) {
            // The meter is defined but did not answer: deny, since we cannot prove the spend is allowed.
            throw new MeterRefusedException('The site spend meter did not answer, so the call was not made.', 'no_decision');
        }
        if (!is_array($decision) || ($decision['allowed'] ?? null) !== true) {
            $reason = is_array($decision) && is_string($decision['reason'] ?? null) ? $decision['reason'] : 'unknown';

            throw new MeterRefusedException('The site spend meter refused the call (' . $reason . ').', $reason);
        }
        $id = $decision['reservation_id'] ?? null;

        return is_string($id) || is_int($id) ? (string) $id : null;
    }

    /**
     * After a call that happened.
     */
    public function record(?string $reservationId, string $provider, string $model, string $status, int $httpStatus, int $in, int $out, ?float $cost): void
    {
        if (!self::active()) {
            return;
        }
        try {
            do_action('spend_meter_record', [
                'reservation_id' => $reservationId,
                'purpose' => self::PURPOSE,
                'caller' => self::CALLER,
                'provider' => $provider,
                'model' => $model,
                'status' => $status,
                'http_status' => $httpStatus,
                'input_tokens' => $in,
                'cached_input_tokens' => 0,
                'output_tokens' => $out,
                'reasoning_tokens' => 0,
                'provider_cost' => $cost,
            ]);
        } catch (\Throwable $e) {
            Logger::log('error', 'spend-meter record threw', ['exception' => get_class($e)]);
        }
    }

    /**
     * When the call was decided against after an allowed check.
     */
    public function release(?string $reservationId): void
    {
        if (!self::active() || $reservationId === null) {
            return;
        }
        try {
            do_action('spend_meter_release', $reservationId);
        } catch (\Throwable $e) {
            Logger::log('error', 'spend-meter release threw', ['exception' => get_class($e)]);
        }
    }
}
