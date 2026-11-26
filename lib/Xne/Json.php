<?php
declare(strict_types=1);

namespace Xne;

final class Json
{
    public static function encode($data, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $s = json_encode($data, $flags);
        if ($s === false) {
            throw new XneException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $s;
    }
}
