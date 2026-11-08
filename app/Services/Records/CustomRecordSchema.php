<?php

namespace App\Services\Records;

use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The bounded field schema of a farm-made record type, and the ONLY place it is turned into validation rules.
 *
 * A farm can never supply rules: it picks one of a fixed set of field kinds and this class maps each kind to a fixed Laravel rule set. The
 * `details` whitelist of a record is exactly the VISIBLE field keys, so a hidden field can never receive a new value.
 *
 * Stored field shape: key, label, kind, required, hidden, plus unit/min/max (number), options (choose_one).
 */
final class CustomRecordSchema
{
    public const MAX_FIELDS = 15;

    public const MAX_OPTIONS = 20;

    public const NUMBER = 'number';

    public const WHOLE_NUMBER = 'whole_number';

    public const SHORT_TEXT = 'short_text';

    public const LONG_TEXT = 'long_text';

    public const DATE = 'date';

    public const YES_NO = 'yes_no';

    public const CHOOSE_ONE = 'choose_one';

    public const KINDS = [self::NUMBER, self::WHOLE_NUMBER, self::SHORT_TEXT, self::LONG_TEXT, self::DATE, self::YES_NO, self::CHOOSE_ONE];

    /** Request rules for a submitted field list (create / update). Prefix is the key of the list, e.g. `fields`. */
    public static function requestRules(string $prefix = 'fields'): array
    {
        return [
            $prefix => ['required', 'array', 'min:1', 'max:'.self::MAX_FIELDS],
            $prefix.'.*' => ['required', 'array:key,label,kind,required,hidden,unit,min,max,options'],
            /** Present for an existing field (it never changes); omit it for a new field and one is made from the label. */
            $prefix.'.*.key' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            $prefix.'.*.label' => ['required', 'string', 'min:1', 'max:60'],
            $prefix.'.*.kind' => ['required', Rule::in(self::KINDS)],
            $prefix.'.*.required' => ['sometimes', 'boolean'],
            $prefix.'.*.hidden' => ['sometimes', 'boolean'],
            $prefix.'.*.unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            $prefix.'.*.min' => ['sometimes', 'nullable'],
            $prefix.'.*.max' => ['sometimes', 'nullable'],
            $prefix.'.*.options' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_OPTIONS],
            $prefix.'.*.options.*' => ['required', 'string', 'min:1', 'max:80'],
        ];
    }

    /**
     * The stored field list for a submitted one: keys assigned, defaults filled, attributes that do not belong to the kind refused, and every
     * cross-field rule checked (422). `$existing` is the type's current list; a submitted key must be one of its keys.
     *
     * @param  list<array<string, mixed>>  $submitted
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    public function normalize(array $submitted, array $existing = []): array
    {
        $known = array_column($existing, null, 'key');
        $used = array_keys($known);
        $labels = [];
        $out = [];
        foreach (array_values($submitted) as $i => $f) {
            $at = 'fields.'.$i;
            $key = $f['key'] ?? null;
            if ($key !== null && ! isset($known[$key])) {
                throw ValidationException::withMessages([$at.'.key' => 'Unknown field key. Leave the key out for a new field.']);
            }
            $label = trim(preg_replace('/\s+/u', ' ', (string) $f['label']));
            if (isset($labels[Str::lower($label)])) {
                throw ValidationException::withMessages([$at.'.label' => 'Two fields cannot have the same name.']);
            }
            $labels[Str::lower($label)] = true;
            if ($key === null) {
                $key = $this->newKey($label, $used);
                $used[] = $key;
            } elseif (in_array($key, array_column($out, 'key'), true)) {
                throw ValidationException::withMessages([$at.'.key' => 'This field is listed twice.']);
            }
            $out[] = $this->field($at, $key, $label, $f);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function field(string $at, string $key, string $label, array $f): array
    {
        $kind = $f['kind'];
        $field = ['key' => $key, 'label' => $label, 'kind' => $kind, 'required' => (bool) ($f['required'] ?? false), 'hidden' => (bool) ($f['hidden'] ?? false)];
        if ($kind === self::NUMBER) {
            $field['unit'] = filled($f['unit'] ?? null) ? trim($f['unit']) : null;
            foreach (['min', 'max'] as $bound) {
                $value = $f[$bound] ?? null;
                $given = $value !== null && $value !== '';
                $parsed = $given ? Decimal::parse($value) : null;
                if ($given && $parsed === null) {
                    throw ValidationException::withMessages([$at.'.'.$bound => 'Use a number with at most 12 digits and 6 decimals.']);
                }
                $field[$bound] = $parsed;
            }
            if ($field['min'] !== null && $field['max'] !== null && Decimal::cmp($field['min'], $field['max']) > 0) {
                throw ValidationException::withMessages([$at.'.max' => 'The largest value cannot be below the smallest value.']);
            }
        } else {
            foreach (['unit', 'min', 'max'] as $attr) {
                if (filled($f[$attr] ?? null)) {
                    throw ValidationException::withMessages([$at.'.'.$attr => 'Only number fields have a '.$attr.'.']);
                }
            }
        }
        if ($kind === self::CHOOSE_ONE) {
            $options = [];
            foreach ((array) ($f['options'] ?? []) as $o) {
                $o = trim(preg_replace('/\s+/u', ' ', (string) $o));
                if (in_array(Str::lower($o), array_map(fn ($x) => Str::lower($x), $options), true)) {
                    throw ValidationException::withMessages([$at.'.options' => 'The same choice is listed twice: '.$o.'.']);
                }
                $options[] = $o;
            }
            if ($options === []) {
                throw ValidationException::withMessages([$at.'.options' => 'Add at least one choice.']);
            }
            $field['options'] = $options;
        } elseif (filled($f['options'] ?? null)) {
            throw ValidationException::withMessages([$at.'.options' => 'Only "choose one" fields have choices.']);
        }

        return $field;
    }

    /** A readable machine key from the label, unique among the keys already used. */
    private function newKey(string $label, array $used): string
    {
        $base = Str::limit(Str::slug($label, '_'), 34, '');
        $base = $base === '' ? 'field' : (ctype_digit($base[0]) ? 'f_'.$base : $base);
        $key = $base;
        for ($n = 2; in_array($key, $used, true); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }

    /**
     * Refuse the edits that would change what existing records mean (409 `custom_type_in_use`): deleting a field, changing its kind, removing
     * a choice, making an optional field required, changing a unit, or narrowing a number's limits. Everything else is fine: add fields,
     * rename labels, add choices, required -> optional, reorder, hide, widen limits.
     *
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     */
    public function assertEditableWithRecords(array $old, array $new): void
    {
        $now = array_column($new, null, 'key');
        foreach ($old as $was) {
            $label = '"'.$was['label'].'"';
            $is = $now[$was['key']] ?? null;
            if ($is === null) {
                $this->locked($was['key'], 'delete', "The field $label already has records, so it cannot be deleted. Hide it instead.");
            }
            if ($is['kind'] !== $was['kind']) {
                $this->locked($was['key'], 'kind', "The field $label already has records, so its type cannot change. Add a new field instead.");
            }
            if (! $was['required'] && $is['required']) {
                $this->locked($was['key'], 'required', "The field $label was optional when records were captured, so it cannot become required. Add a new required field instead.");
            }
            foreach (array_diff($was['options'] ?? [], $is['options'] ?? []) as $gone) {
                $this->locked($was['key'], 'option', "The choice \"$gone\" of $label is already used by records, so it cannot be removed. Hide the field or add a new one.");
            }
            if ($was['kind'] === self::NUMBER) {
                if (($is['unit'] ?? null) !== ($was['unit'] ?? null)) {
                    $this->locked($was['key'], 'unit', "The unit of $label cannot change once records exist.");
                }
                $narrowerMin = $is['min'] !== null && ($was['min'] === null || Decimal::cmp($is['min'], $was['min']) > 0);
                $narrowerMax = $is['max'] !== null && ($was['max'] === null || Decimal::cmp($is['max'], $was['max']) < 0);
                if ($narrowerMin || $narrowerMax) {
                    $this->locked($was['key'], 'limits', "The limits of $label can only be widened once records exist.");
                }
            }
        }
    }

    private function locked(string $key, string $rule, string $message): never
    {
        throw new ApiHttpException(409, 'custom_type_in_use', $message, details: ['field_key' => $key, 'rule' => $rule]);
    }

    // ------------------------------------------------------------------ recording

    /** Keys a new record may carry: the visible fields only. */
    public function visibleKeys(array $fields): array
    {
        return array_values(array_map(fn ($f) => $f['key'], array_filter($fields, fn ($f) => ! $f['hidden'])));
    }

    /** The summable fields - `number` and `whole_number` (hidden ones included: old records still carry them). */
    public function numberFields(array $fields): array
    {
        return array_values(array_filter($fields, fn ($f) => in_array($f['kind'], [self::NUMBER, self::WHOLE_NUMBER], true)));
    }

    /**
     * Laravel rules for `details` of a new record. Hidden fields are not asked for, even when they were required.
     *
     * @return array<string, mixed>
     */
    public function detailRules(array $fields): array
    {
        $visible = array_values(array_filter($fields, fn ($f) => ! $f['hidden']));
        $keys = array_column($visible, 'key');
        $rules = ['details' => $keys === [] ? ['present', 'array', 'max:0'] : ['present', 'array:'.implode(',', $keys)]];
        foreach ($visible as $f) {
            $rules['details.'.$f['key']] = array_merge($f['required'] ? ['required'] : ['sometimes', 'nullable'], $this->kindRules($f));
        }

        return $rules;
    }

    /** @return list<mixed> */
    private function kindRules(array $f): array
    {
        return match ($f['kind']) {
            self::NUMBER => [function ($attribute, $value, $fail) use ($f) {
                $n = is_array($value) || is_bool($value) ? null : Decimal::parse($value);
                if ($n === null) {
                    $fail('Enter a number (up to 12 digits and 6 decimals).');
                } elseif (($f['min'] ?? null) !== null && Decimal::cmp($n, $f['min']) < 0) {
                    $fail('It must be at least '.$f['min'].'.');
                } elseif (($f['max'] ?? null) !== null && Decimal::cmp($n, $f['max']) > 0) {
                    $fail('It must be at most '.$f['max'].'.');
                }
            }],
            self::WHOLE_NUMBER => [function ($attribute, $value, $fail) {
                $n = is_array($value) || is_bool($value) ? null : Decimal::parse($value);
                if ($n === null || ! Decimal::isInteger($n) || Decimal::isNegative($n)) {
                    $fail('Enter a whole number (0 or more).');
                }
            }],
            self::SHORT_TEXT => ['string', 'max:200'],
            self::LONG_TEXT => ['string', 'max:2000'],
            self::DATE => ['string', 'date_format:Y-m-d'],
            self::YES_NO => [function ($attribute, $value, $fail) {
                if (! is_bool($value)) {
                    $fail('Choose yes or no (true or false).');
                }
            }],
            self::CHOOSE_ONE => ['string', Rule::in($f['options'])],
        };
    }

    /**
     * The validated details in stored form: numbers as exact decimal strings, whole numbers as integers, yes/no as booleans, empty values left
     * out, in the order of the schema.
     *
     * @return array<string, mixed>
     */
    public function normalizeDetails(array $fields, array $details): array
    {
        $out = [];
        foreach ($fields as $f) {
            $value = $details[$f['key']] ?? null;
            if ($f['hidden'] || $value === null) {
                continue;
            }
            $out[$f['key']] = match ($f['kind']) {
                self::NUMBER => Decimal::parse($value),
                self::WHOLE_NUMBER => (int) Decimal::parse($value),
                self::YES_NO => (bool) $value,
                default => is_string($value) ? trim($value) : $value,
            };
        }

        return $out;
    }
}
