<?php

declare(strict_types=1);

namespace LofAudioSupply\Viewer;

use LofAudioSupply\Support\Json;

/**
 * The JSON Schema (2020-12) subset the frozen phone-audio contract V1 uses.
 *
 * A line-for-line port of the contract's normative `Schema` class. Any keyword
 * outside SUPPORTED is reported by unsupported(), so a schema file can never
 * silently mean more here than it does in the reference model.
 */
final class ContractSchema
{
    public const SUPPORTED = [
        '$schema', '$id', '$comment', 'title', 'description',
        'type', 'const', 'enum', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'required', 'properties', 'patternProperties', 'additionalProperties', 'minProperties', 'maxProperties',
        'items', 'minItems', 'maxItems', 'oneOf',
    ];

    /**
     * @param mixed $schema
     * @return list<string>
     */
    public static function unsupported($schema, string $at = '#'): array
    {
        $bad = [];
        if (!is_object($schema)) {
            return [$at . ' is not a schema object'];
        }
        foreach (get_object_vars($schema) as $k => $v) {
            if (!in_array($k, self::SUPPORTED, true)) {
                $bad[] = $at . '/' . $k;
            }
        }
        foreach (['properties', 'patternProperties'] as $kw) {
            if (isset($schema->$kw)) {
                foreach (get_object_vars($schema->$kw) as $k => $sub) {
                    $bad = array_merge($bad, self::unsupported($sub, $at . '/' . $kw . '/' . $k));
                }
            }
        }
        foreach (['additionalProperties', 'items'] as $kw) {
            if (isset($schema->$kw) && is_object($schema->$kw)) {
                $bad = array_merge($bad, self::unsupported($schema->$kw, $at . '/' . $kw));
            }
        }
        if (isset($schema->oneOf)) {
            foreach ($schema->oneOf as $i => $sub) {
                $bad = array_merge($bad, self::unsupported($sub, $at . '/oneOf/' . $i));
            }
        }

        return $bad;
    }

    /** @param mixed $v */
    private static function typeOf($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v)) {
            return 'integer';
        }
        if (is_float($v)) {
            return 'number';
        }
        if (is_string($v)) {
            return 'string';
        }
        if ($v instanceof \stdClass) {
            return 'object';
        }

        return 'array';
    }

    /** @param mixed $x */
    private static function norm($x): string
    {
        return Json::canonical(json_decode(json_encode($x, JSON_THROW_ON_ERROR), true));
    }

    private static function re(string $p): string
    {
        return '/' . str_replace('/', '\/', $p) . '/u';
    }

    /**
     * @param mixed $v a json_decode(..., false) tree
     * @param object $s
     * @return list<string>
     */
    public static function errors($v, $s, string $at = '$'): array
    {
        $e = [];
        $t = self::typeOf($v);
        if (isset($s->type)) {
            $types = (array) $s->type;
            if (!in_array($t, $types, true)) {
                return [$at . ': type ' . $t];
            }
        }
        if (property_exists($s, 'const') && self::norm($v) !== self::norm($s->const)) {
            $e[] = $at . ': const';
        }
        if (isset($s->enum)) {
            $hit = false;
            foreach ($s->enum as $c) {
                $hit = $hit || self::norm($v) === self::norm($c);
            }
            if (!$hit) {
                $e[] = $at . ': enum';
            }
        }
        if ($t === 'string') {
            $len = preg_match_all('/./su', $v);
            if (isset($s->pattern) && preg_match(self::re($s->pattern), $v) !== 1) {
                $e[] = $at . ': pattern';
            }
            if (isset($s->minLength) && $len < $s->minLength) {
                $e[] = $at . ': minLength';
            }
            if (isset($s->maxLength) && $len > $s->maxLength) {
                $e[] = $at . ': maxLength';
            }
        }
        if ($t === 'integer') {
            if (isset($s->minimum) && $v < $s->minimum) {
                $e[] = $at . ': minimum';
            }
            if (isset($s->maximum) && $v > $s->maximum) {
                $e[] = $at . ': maximum';
            }
        }
        if ($t === 'object') {
            $props = get_object_vars($v);
            foreach (($s->required ?? []) as $r) {
                if (!array_key_exists($r, $props)) {
                    $e[] = $at . ': required ' . $r;
                }
            }
            if (isset($s->minProperties) && count($props) < $s->minProperties) {
                $e[] = $at . ': minProperties';
            }
            if (isset($s->maxProperties) && count($props) > $s->maxProperties) {
                $e[] = $at . ': maxProperties';
            }
            foreach ($props as $k => $pv) {
                $k = (string) $k;
                $matched = false;
                if (isset($s->properties) && property_exists($s->properties, $k)) {
                    $matched = true;
                    $e = array_merge($e, self::errors($pv, $s->properties->$k, $at . '.' . $k));
                }
                if (isset($s->patternProperties)) {
                    foreach (get_object_vars($s->patternProperties) as $pat => $ps) {
                        if (preg_match(self::re((string) $pat), $k) === 1) {
                            $matched = true;
                            $e = array_merge($e, self::errors($pv, $ps, $at . '.' . $k));
                        }
                    }
                }
                if (!$matched && isset($s->additionalProperties)) {
                    if ($s->additionalProperties === false) {
                        $e[] = $at . ': additional ' . $k;
                    } elseif (is_object($s->additionalProperties)) {
                        $e = array_merge($e, self::errors($pv, $s->additionalProperties, $at . '.' . $k));
                    }
                }
            }
        }
        if ($t === 'array') {
            if (isset($s->minItems) && count($v) < $s->minItems) {
                $e[] = $at . ': minItems';
            }
            if (isset($s->maxItems) && count($v) > $s->maxItems) {
                $e[] = $at . ': maxItems';
            }
            if (isset($s->items) && is_object($s->items)) {
                foreach ($v as $i => $iv) {
                    $e = array_merge($e, self::errors($iv, $s->items, $at . '[' . $i . ']'));
                }
            }
        }
        if (isset($s->oneOf)) {
            $n = 0;
            foreach ($s->oneOf as $sub) {
                $n += self::errors($v, $sub, $at) === [] ? 1 : 0;
            }
            if ($n !== 1) {
                $e[] = $at . ': oneOf matched ' . $n;
            }
        }

        return $e;
    }
}
