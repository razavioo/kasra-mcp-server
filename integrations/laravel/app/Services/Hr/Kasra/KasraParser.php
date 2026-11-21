<?php

namespace App\Services\Hr\Kasra;

use DOMDocument;
use DOMXPath;

final class KasraParser
{
    public function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function inputs(string $html): array
    {
        $values = [];
        foreach ($this->document($html)->query('//input') as $input) {
            foreach (['id', 'name'] as $attribute) {
                if ($input->hasAttribute($attribute)) {
                    $values[$input->getAttribute($attribute)] = $input->getAttribute('value');
                }
            }
        }

        return $values;
    }

    /** Read literal configuration values, never execute source JavaScript. */
    public function setting(string $html, string $name): string
    {
        $pattern = '~'.preg_quote($name, '~').'\s*=\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|([0-9]+))\s*;~u';
        if (! preg_match($pattern, $html, $matches)) {
            return '';
        }

        return html_entity_decode(str_replace(['\\/', '\\"', "\\'"], ['/', '"', "'"], $matches[1] ?: ($matches[2] ?: ($matches[3] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s_\-\x{200c}]+/u', '', strtr($value, ['ي' => 'ی', 'ك' => 'ک', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])));
    }

    public function value(array $row, array $names, string $default = ''): string
    {
        $keys = array_map(fn ($key) => $this->normalize((string) $key), array_keys($row));
        foreach ($names as $name) {
            $index = array_search($this->normalize($name), $keys, true);
            if ($index !== false) {
                $value = array_values($row)[$index];
                if (is_scalar($value)) {
                    return trim((string) $value);
                }
            }
        }

        return $default;
    }

    /** Preserve units such as "days,hours" instead of guessing a shift length. */
    public function monthlyPerson(array $row, array $columns = []): ?array
    {
        foreach ($columns as $field => $label) {
            if (array_key_exists($field, $row)) {
                $row[$label] = $row[$field];
            }
        }
        $code = $this->value($row, ['PersonCode', 'PCode', 'CodeMask', 'کد پرسنلی', 'پرسنلی']);
        $name = $this->value($row, ['PersonName', 'FullName', 'Name', 'نام']);
        if (in_array($name, ['جمع', 'مجموع', 'جمع کل'], true) || $this->value($row, ['RowNumber', 'Row', 'ردیف']) === 'جمع') {
            return null;
        }
        if ($code === '' || $name === '') {
            throw new KasraException(__('ستون‌های کد پرسنلی و نام در گزارش کسرا شناسایی نشدند؛ قالب گزارش باید بررسی شود.'));
        }
        $metrics = [];
        foreach ([
            'حضور' => ['کل حضور', 'TotalPresence'],
            'کارکرد' => ['کارکرد', 'Worked', 'WorkTime'],
            'اضافه‌کار' => ['اضافه کار پرداختی', 'اضافه کار عادی', 'Overtime'],
            'کسری' => ['کسرحضور', 'کسر حضور', 'DeficitPresence'],
            'مرخصی روزانه' => ['استحقاقی روزانه', 'DailyLeave'],
            'مرخصی ساعتی' => ['استحقاقی ساعتی', 'HourlyLeave'],
            'مأموریت روزانه' => ['ماموریت روزانه', 'DailyMission'],
            'مأموریت ساعتی' => ['ماموریت ساعتی', 'HourlyMission'],
            'دورکاری روزانه' => ['دورکاری روزانه', 'RemoteDaily'],
            'دورکاری ساعتی' => ['دور کاری ساعتی', 'RemoteHourly'],
        ] as $label => $aliases) {
            $metrics[$label] = $this->value($row, $aliases, '—');
        }

        return ['code' => $code, 'name' => $name, 'metrics' => $metrics];
    }

    /** Presentation only: never alter the provider type, document or fingerprint. */
    public function requestLabel(array $request): string
    {
        $type = trim((string) ($request['type'] ?? ''));
        $generic = ['', 'مجوز', 'درخواست', 'درخواستمجوز', 'روزانه', 'ساعتی', 'تردد'];
        $candidates = in_array($this->normalize($type), $generic, true)
            ? [(string) ($request['title'] ?? '')] : [$type];
        $labels = [
            'مرخصی استحقاقی روزانه' => ['مرخصی استحقاقی روزانه', 'استحقاقی روزانه'],
            'مرخصی استحقاقی ساعتی' => ['مرخصی استحقاقی ساعتی', 'استحقاقی ساعتی'],
            'مرخصی استعلاجی' => ['مرخصی استعلاجی', 'استعلاجی'],
            'مرخصی بدون حقوق' => ['مرخصی بدون حقوق', 'بدون حقوق'],
            'مرخصی روزانه' => ['مرخصی روزانه'],
            'مرخصی ساعتی' => ['مرخصی ساعتی'],
            'دورکاری روزانه' => ['دورکاری روزانه'],
            'دورکاری ساعتی' => ['دورکاری ساعتی'],
            'دورکاری' => ['دورکاری'],
            'مأموریت روزانه' => ['ماموریت روزانه', 'مأموریت روزانه'],
            'مأموریت ساعتی' => ['ماموریت ساعتی', 'مأموریت ساعتی'],
            'مأموریت' => ['ماموریت', 'مأموریت'],
            'اضافه‌کاری دورکاری' => ['اضافه کار دورکاری'],
            'اضافه‌کاری' => ['اضافه کاری', 'اضافه کار'],
            'درخواست تردد' => ['درخواست تردد'],
            'اصلاح تردد' => ['اصلاح تردد'],
        ];
        foreach ($candidates as $candidate) {
            $value = $this->normalize($candidate);
            foreach (['درخواستمجوز', 'درخواست', 'مجوز'] as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    $value = ltrim(substr($value, strlen($prefix)), ':：');
                    break;
                }
            }
            foreach ($labels as $label => $aliases) {
                foreach ($aliases as $alias) {
                    if (str_starts_with($value, $this->normalize($alias))) return $label;
                }
            }
        }
        return in_array($this->normalize($type), $generic, true) ? __('نوع درخواست مشخص نیست') : $type;
    }

    public function request(array $row, string $status): array
    {
        $id = $this->value($row, ['DocID']);
        if ($id === '' || ! ctype_digit($id)) {
            throw new KasraException(__('شناسهٔ معتبر سند در پاسخ کارتابل کسرا وجود ندارد.'));
        }
        $stable = [];
        foreach (['DocID', 'DocTypeID', 'Action', 'StepNo', 'ProcessInstanceId', 'DocMemberID', 'requesterCode', 'requester', 'doctypename', 'SDate', 'EDate', 'RequestDate', 'description', 'RequesterDescr'] as $key) {
            $stable[$key] = $this->value($row, [$key]);
        }

        return [
            'id' => $id,
            'code' => $this->value($row, ['requesterCode', 'DocMemberCode', 'PersonCode']),
            'name' => $this->value($row, ['requester', 'PersonName', 'DocMemberName']),
            'title' => $this->value($row, ['description', 'Subject']),
            'type' => $this->value($row, ['doctypename']),
            'note' => $this->value($row, ['RequesterDescr']),
            'requested_at' => $this->value($row, ['RequestDate']),
            'status' => $status,
            'fingerprint' => hash('sha256', json_encode($stable, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'source' => $row,
        ];
    }

    /** Kendo grids and ASP.NET report envelopes, including explicit totals. */
    public function grid(array $response): array
    {
        $payload = $response['d'] ?? $response;
        $columns = [];
        if (is_string($payload)) {
            $text = trim($payload);
            if (str_starts_with($text, '<')) {
                $end = strpos($text, '</Root>');
                if ($end === false) {
                    throw new KasraException(__('ساختار گزارش کسرا قابل شناسایی نیست.'));
                }
                $xml = substr($text, 0, $end + 7);
                foreach ($this->document($xml)->query('//*') as $node) {
                    $attrs = [];
                    foreach ($node->attributes ?? [] as $attribute) {
                        $attrs[$attribute->name] = $attribute->value;
                    }
                    $field = $this->value($attrs, ['field', 'DataField', 'ColumnName', 'Name']);
                    $label = $this->value($attrs, ['title', 'Caption', 'HeaderText', 'CodeName']);
                    if ($field !== '' && $label !== '') {
                        $columns[$field] = $label;
                    }
                }
                $text = trim(substr($text, $end + 7));
            }
            $payload = json_decode(explode('^', $text, 2)[0], true);
        }
        if (! is_array($payload)) {
            throw new KasraException(__('کسرا پاسخ معتبر برای فهرست اطلاعات برنگرداند.'));
        }
        if (array_is_list($payload)) {
            return ['rows' => $payload, 'total' => null, 'columns' => $columns];
        }
        $rows = $payload['Data'] ?? $payload['data'] ?? null;
        $total = $payload['Total'] ?? $payload['total'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ($total !== null && ! is_numeric($total))) {
            throw new KasraException(__('قالب یا صفحه‌بندی پاسخ کسرا نیاز به بررسی دارد.'));
        }
        if (! empty($payload['Errors']) || ! empty($payload['errors'])) {
            throw new KasraException(__('کسرا دریافت فهرست را نپذیرفت.'));
        }

        return ['rows' => $rows, 'total' => $total === null ? null : (int) $total, 'columns' => $columns];
    }

    public function daily(string $html, string $expectedCode): array
    {
        $inputs = $this->inputs($html);
        $actual = $inputs['ctl00_ContentPlaceHolder1_CmbPerson_txtPCode'] ?? '';
        if ($actual !== $expectedCode) {
            throw new KasraException(__('پرسنل گزارش روزانه با شخص انتخاب‌شده یکسان نیست؛ داده ذخیره نشد.'));
        }
        $xpath = $this->document($html);
        $rows = $xpath->query('//table[@id="ctl00_ContentPlaceHolder1_GrdDailyReport"]//tr');
        if ($rows->length === 0) {
            throw new KasraException(__('جدول گزارش روزانه در پاسخ کسرا موجود نیست.'));
        }
        $headers = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) as $cell) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
            }
            if ($index === 0) {
                $headers = $cells;

                continue;
            }
            $record = [];
            foreach ($headers as $i => $header) {
                if ($header !== '' && isset($cells[$i])) {
                    $record[$header] = $cells[$i];
                }
            }
            $date = $this->value($record, ['تاریخ']);
            if (preg_match('~^[0-9۰-۹]{4}/[0-9۰-۹]{2}/[0-9۰-۹]{2}$~u', $date)) {
                $result[] = ['date' => $date, 'values' => $record];
            }
        }

        return $result;
    }
}
