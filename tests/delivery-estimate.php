<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Product\DeliveryEstimate as Estimate;

$check = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$at = static fn (string $date, array $changes = []): ?array => Estimate::present(array_replace(
    Estimate::defaults(), ['enabled' => true, 'min_days' => 1, 'max_days' => 1], $changes),
    new DateTimeImmutable($date, new DateTimeZone('Europe/Prague')));
$check(Estimate::present(null) === null && Estimate::present(['enabled' => false]) === null, 'Unconfigured estimate must be hidden.');
$check($at('2026-07-13 09:00')['text'] === 'Zítra u vás', 'Tomorrow wording.');
$check($at('2026-07-13 12:00')['text'] === 'Pozítří u vás', 'Cutoff must move the estimate, including its relative wording.');
$check($at('2026-07-17 09:00')['from'] === '2026-07-20', 'Friday must not promise Saturday.');
$check($at('2026-07-18 09:00')['from'] === '2026-07-21', 'Weekend orders start processing on Monday.');
$check($at('2026-04-02 09:00')['from'] === '2026-04-07', 'Good Friday and Easter Monday must be skipped.');
$check($at('2027-03-25 09:00')['from'] === '2027-03-30', 'Easter calculation must not hardcode one year.');
$check($at('2026-10-27 09:00')['from'] === '2026-10-29', 'Czech public holiday must be skipped.');
$check($at('2026-12-23 09:00')['from'] === '2026-12-28', 'Christmas and weekend must be skipped.');
$check($at('2026-07-17 09:00', ['day_type' => 'calendar'])['text'] === 'Zítra u vás', 'Calendar mode must include weekends.');
$check($at('2026-07-13 23:59', ['cutoff' => ''])['text'] === 'Zítra u vás', 'Optional cutoff.');
$check($at('2026-07-13 09:00', ['min_days' => 3, 'max_days' => 4])['text'] === 'Za tři až čtyři pracovní dny u vás', 'Working-day range grammar.');
$check($at('2026-07-13 09:00', ['min_days' => 3, 'max_days' => 4, 'day_type' => 'calendar'])['text'] === 'Za tři až čtyři dny u vás', 'Calendar range grammar.');
$check($at('2026-07-13 12:00', ['min_days' => 3, 'max_days' => 4])['text'] === 'Za čtyři až pět pracovních dnů u vás', 'Range must include cutoff delay.');
$check($at('2026-07-13 09:00', ['format' => 'within', 'max_days' => 3])['text'] === 'U vás do tří pracovních dnů', 'Within uses maximum.');
$check($at('2026-07-13 09:00', ['format' => 'within', 'max_days' => 3, 'day_type' => 'calendar'])['text'] === 'U vás do tří dnů', 'Within calendar grammar.');
$check($at('2026-07-13 09:00', ['format' => 'within'])['text'] === 'U vás do jednoho pracovního dne', 'Singular grammar.');
$check($at('2026-07-13 09:00', ['format' => 'date'])['text'] === 'U vás 14. 7.', 'Date format.');
$check($at('2026-12-31 09:00', ['format' => 'date'])['text'] === 'U vás 4. 1. 2027', 'Date crossing year.');
$check($at('2026-03-27 09:00')['from'] === '2026-03-30', 'Daylight saving must use local calendar days.');
$check($at('2026-07-13T10:00:00+00:00')['text'] === 'Pozítří u vás', 'Cutoff must use Prague timezone rather than server timezone.');
foreach ([['min_days' => 0], ['max_days' => 31], ['min_days' => 5, 'max_days' => 4],
    ['min_days' => []], ['enabled' => []], ['cutoff' => '24:00'], ['cutoff' => []],
    ['format' => '<script>'], ['day_type' => 'unknown']] as $bad) {
    try { Estimate::validate(array_replace(Estimate::defaults(), $bad)); throw new RuntimeException('Invalid delivery settings accepted.'); }
    catch (InvalidArgumentException $expected) {}
    $check(Estimate::present(array_replace(Estimate::defaults(), ['enabled' => true], $bad)) === null, 'Bad persisted settings must safely disable the estimate.');
}
echo "Delivery estimate calendar, wording and validation passed.\n";
