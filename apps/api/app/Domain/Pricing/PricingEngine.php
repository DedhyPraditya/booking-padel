<?php

namespace App\Domain\Pricing;

use App\Models\Court;
use App\Models\PricingRule;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PricingEngine
{
    /**
     * Calculate price quote for a court and interval [startAt, endAt).
     *
     * @param  Collection<int, PricingRule>|null  $preloadedRules
     * @return array{
     *     court_id: string,
     *     duration_minutes: int,
     *     unit_price_per_hour: string,
     *     subtotal: string,
     *     discount_amount: string,
     *     tax_amount: string,
     *     total_amount: string,
     *     currency: string,
     *     applied_rules: array<int, array<string, mixed>>
     * }
     */
    public function quote(
        Venue $venue,
        Court $court,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        ?Collection $preloadedRules = null,
    ): array {
        $tz = $venue->timezone ?: 'Asia/Makassar';
        $localStart = $startAt->setTimezone($tz);
        $localEnd = $endAt->setTimezone($tz);

        if ($localEnd->lessThanOrEqualTo($localStart)) {
            throw new InvalidArgumentException('End time must be after start time.');
        }

        $stepMinutes = max(15, (int) ($venue->slot_interval_minutes ?: 30));
        $totalMinutes = (int) $localStart->diffInMinutes($localEnd);

        $rules = $preloadedRules ?? PricingRule::query()
            ->where('venue_id', $venue->id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        $cursor = $localStart;
        $totalAmount = 0.0;
        $appliedRules = [];

        while ($cursor->lessThan($localEnd)) {
            $next = $cursor->addMinutes($stepMinutes);
            if ($next->greaterThan($localEnd)) {
                $next = $localEnd;
            }

            $sliceMinutes = (int) $cursor->diffInMinutes($next);
            $matchedRule = $this->matchRuleForSlice($rules, $court->id, $cursor, $next);

            if (! $matchedRule) {
                throw new InvalidArgumentException(
                    sprintf('No active pricing rule covers %s - %s.', $cursor->format('H:i'), $next->format('H:i'))
                );
            }

            $ratePerHour = (float) $matchedRule->price_per_hour;
            $slicePrice = round($ratePerHour * ($sliceMinutes / 60.0), 2);
            $totalAmount += $slicePrice;

            $appliedRules[] = [
                'rule_id' => $matchedRule->id,
                'rule_name' => $matchedRule->name,
                'start' => $cursor->format('H:i'),
                'end' => $next->format('H:i'),
                'minutes' => $sliceMinutes,
                'price_per_hour' => number_format($ratePerHour, 2, '.', ''),
                'slice_total' => number_format($slicePrice, 2, '.', ''),
            ];

            $cursor = $next;
        }

        $effectiveRatePerHour = $totalMinutes > 0
            ? round($totalAmount / ($totalMinutes / 60.0), 2)
            : 0.0;

        $formattedSubtotal = number_format($totalAmount, 2, '.', '');

        return [
            'court_id' => $court->id,
            'duration_minutes' => $totalMinutes,
            'unit_price_per_hour' => number_format($effectiveRatePerHour, 2, '.', ''),
            'subtotal' => $formattedSubtotal,
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => $formattedSubtotal,
            'currency' => 'IDR',
            'applied_rules' => $appliedRules,
        ];
    }

    /**
     * @param  Collection<int, PricingRule>  $rules
     */
    private function matchRuleForSlice(
        Collection $rules,
        string $courtId,
        CarbonImmutable $sliceStart,
        CarbonImmutable $sliceEnd,
    ): ?PricingRule {
        $dateStr = $sliceStart->format('Y-m-d');
        $dayOfWeek = $sliceStart->dayOfWeek;
        $startTimeStr = $sliceStart->format('H:i:s');
        $endTimeStr = $sliceEnd->format('H:i:s');

        $candidates = $rules->filter(function (PricingRule $rule) use ($courtId, $dateStr, $dayOfWeek, $startTimeStr, $endTimeStr) {
            if (! $rule->is_active) {
                return false;
            }

            if ($rule->court_id !== null && $rule->court_id !== $courtId) {
                return false;
            }

            if ($rule->day_of_week !== null && (int) $rule->day_of_week !== $dayOfWeek) {
                return false;
            }

            if ($rule->valid_from !== null && $dateStr < $rule->valid_from->format('Y-m-d')) {
                return false;
            }

            if ($rule->valid_until !== null && $dateStr > $rule->valid_until->format('Y-m-d')) {
                return false;
            }

            $ruleStart = strlen((string) $rule->start_time) === 5 ? $rule->start_time.':00' : (string) $rule->start_time;
            $ruleEnd = strlen((string) $rule->end_time) === 5 ? $rule->end_time.':00' : (string) $rule->end_time;

            return $ruleStart <= $startTimeStr && $ruleEnd >= $endTimeStr;
        });

        // Order by priority DESC, then court-specific first, then day-specific first
        return $candidates->sort(function (PricingRule $a, PricingRule $b) {
            if ($a->priority !== $b->priority) {
                return $b->priority <=> $a->priority;
            }
            $aCourtSpec = $a->court_id !== null ? 1 : 0;
            $bCourtSpec = $b->court_id !== null ? 1 : 0;
            if ($aCourtSpec !== $bCourtSpec) {
                return $bCourtSpec <=> $aCourtSpec;
            }
            $aDaySpec = $a->day_of_week !== null ? 1 : 0;
            $bDaySpec = $b->day_of_week !== null ? 1 : 0;

            return $bDaySpec <=> $aDaySpec;
        })->first();
    }
}
