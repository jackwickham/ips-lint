<?php

namespace IpsLint\Utils;

final class StringUtils {
    private function __construct() {
    }

    public static function extractLines(string $str, int $startLineInclusive, int $endLineInclusive): ?string {
        $startLine = $startLineInclusive - 1;
        $numLines = $endLineInclusive - $startLine;
        preg_match("/^(?:.*\n){{$startLine}}((?:.*\n){{$numLines}})/", $str, $matches);
        return $matches[1] ?? null;
    }
}
