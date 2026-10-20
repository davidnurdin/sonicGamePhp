<?php

namespace SonicGame\Save;

/**
 * Best times of each act (title menu > best times), kept in var/besttimes.json: the 5 fastest completions of
 * every act 1-18 with the player's name and the date. The time is the act timer when it is completed (the one of the tally),
 * the Scrap Brain transporter rooms and the Sky Base interior added to the act they belong to.
 */
class BestTimes
{
    public const MAX = 5;

    /** @var array<int, array<int, array{time: float, date: string, name: string}>> by act */
    private array $times = [];
    /** [act, index] of the time added last (shown blinking), or null. */
    private ?array $last = null;

    public function __construct(private string $file)
    {
        $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($saved)) return;
        foreach ($saved as $act => $entries) {
            foreach ((array)$entries as $entry) {
                if (isset($entry['time'], $entry['date'])) {
                    $this->times[(int)$act][] = ['time' => (float)$entry['time'], 'date' => (string)$entry['date'],
                        'name' => (string)($entry['name'] ?? '')];
                }
            }
        }
    }

    /** @return array<int, array{time: float, date: string, name: string}> fastest first */
    public function top(int $act): array
    {
        return $this->times[$act] ?? [];
    }

    /** Index of the time added last in this act, or null. */
    public function lastIndex(int $act): ?int
    {
        return $this->last !== null && $this->last[0] === $act ? $this->last[1] : null;
    }

    /**
     * Act $level completed in $time seconds by $name; kept when it is among the 5 best.
     * Returns [act, index in its top 5 or null], or null for a level that is not an act.
     */
    public function add(int $level, float $time, string $name = ''): ?array
    {
        $act = self::actOf($level);
        if ($act === null || $time <= 0) return null;
        $entry = ['time' => round($time, 2), 'date' => date('d.m.y'), 'name' => $name];
        $list = $this->times[$act] ?? [];
        $list[] = $entry;
        // equal times: the older one stays first
        usort($list, fn($a, $b) => $a['time'] <=> $b['time']);
        $list = array_slice($list, 0, self::MAX);
        $index = array_search($entry, $list, true);
        if ($index === false) return [$act, null];
        $this->times[$act] = $list;
        $this->last = [$act, $index];
        $this->save();
        return [$act, $index];
    }

    /** The name typed for a time just added. */
    public function setName(int $act, int $index, string $name): void
    {
        if (!isset($this->times[$act][$index])) return;
        $this->times[$act][$index]['name'] = $name;
        $this->save();
    }

    private function save(): void
    {
        @mkdir(dirname($this->file), 0777, true);
        @file_put_contents($this->file, json_encode($this->times, JSON_PRETTY_PRINT));
    }

    /** Act 1-18 of a level: the transporter rooms belong to Scrap Brain 2, the interior to Sky Base 2. */
    public static function actOf(int $level): ?int
    {
        if ($level >= 20 && $level <= 25) return 14;
        if ($level === 26 || $level === 27) return 17;
        return $level >= 1 && $level <= 18 ? $level : null;
    }

    /** "ZONE 2 ACTE 3". */
    public static function place(int $act): string
    {
        return \SonicGame\Utils\Lang::t('ZONE {zone} ACTE {act}', ['zone' => intdiv($act - 1, 3) + 1, 'act' => ($act - 1) % 3 + 1]);
    }

    /** "1:23.45" */
    public static function format(float $time): string
    {
        $hundredths = (int)round($time * 100);
        return sprintf('%d:%02d.%02d', intdiv($hundredths, 6000), intdiv($hundredths, 100) % 60, $hundredths % 100);
    }
}
