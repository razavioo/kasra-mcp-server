<?php

namespace App\Services\Hr\Kasra;

use App\Helpers\Jalali;
use App\Models\KasraConnection;
use App\Models\KasraRecord;
use Carbon\Carbon;

/** Keeps source units and every punch; missing data is never an absence or zero. */
class KasraDailyReport
{
    public static function jalali(Carbon $date): string
    {
        $formatter = new \IntlDateFormatter('en_US@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
        return (string) $formatter->format($date->getTimestamp());
    }

    public static function normalize(array $row): array
    {
        $parser = new KasraParser;
        $values = $row['values'] ?? [];
        $fields = [
            'punches' => ['ترددها', 'تردد', 'ورود و خروج'],
            'presence' => ['حضور', 'کل حضور'],
            'worked' => ['کارکرد', 'کارکرد خالص'],
            'shift_presence' => ['ح.شیفت', 'حضور در شیفت'],
            'overtime' => ['اضافه کار عادی', 'اضافه کار پرداختی'],
            'deficit' => ['کسر حضور', 'سرجمع کسر کار'],
            'daily_leave' => ['استحقاقی روزانه'],
            'hourly_leave' => ['استحقاقی ساعتی'],
            'remote' => ['دور کاری ساعتی', 'دورکاری روزانه'],
        ];
        $result = ['date' => $row['date'] ?? '', 'values' => $values, 'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : []];
        foreach ($fields as $key => $aliases) {
            $value = $parser->value($values, $aliases, '—');
            if ($key === 'punches' && (str_starts_with($parser->normalize($value), 'ساختار') || str_starts_with($parser->normalize($value), 'شیفت'))) $value = '';
            $result[$key] = $value === '' ? '—' : $value;
        }
        return $result;
    }

    /** Must be called inside the connection-generation transaction. */
    public function store(KasraConnection $connection, array $people, string $start, string $end): void
    {
        foreach ($people as $person) {
            if (! is_string($person['code'] ?? null) || ! is_array($person['rows'] ?? null)) {
                throw new KasraException(__('گزارش روزانهٔ کسرا کامل نیست؛ اطلاعات قبلی حفظ شد.'));
            }
            $dates = [];
            foreach ($person['rows'] as $row) {
                $date = Jalali::toGregorian($row['date'] ?? '');
                if (! $date || ! is_array($row['values'] ?? null)) throw new KasraException(__('تاریخ گزارش روزانه معتبر نیست.'));
                $day = $date->toDateString();
                if ($day < $start || $day > $end) continue;
                if (isset($dates[$day])) throw new KasraException(__('روز تکراری در پاسخ کسرا دریافت شد.'));
                $dates[$day] = true;
                $payload = self::normalize($row);
                KasraRecord::updateOrCreate([
                    'connection_id' => $connection->id, 'generation' => $connection->generation,
                    'kind' => 'daily', 'external_key' => $person['code'].':'.$day,
                ], [
                    'personnel_code' => $person['code'], 'person_name' => $person['name'], 'period' => $day,
                    'payload' => $payload, 'fingerprint' => hash('sha256', json_encode($payload)), 'observed_at' => now(),
                ]);
            }
            // A successful complete read may remove a previously returned day.
            KasraRecord::where('connection_id', $connection->id)->where('generation', $connection->generation)
                ->where('kind', 'daily')->where('personnel_code', $person['code'])->whereBetween('period', [$start, $end])
                ->whereNotIn('period', array_keys($dates))->delete();
        }
    }
}
