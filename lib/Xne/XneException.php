<?php
declare(strict_types=1);

namespace Xne;

/**
 * A failure the engine reports to the user. The code is the exit code: 1 FAIL, 2 usage, 3 refused by a safety check.
 */
class XneException extends \RuntimeException
{
    public const FAIL = 1;
    public const USAGE = 2;
    public const REFUSED = 3;

    public static function usage(string $msg): self
    {
        return new self($msg, self::USAGE);
    }

    public static function refused(string $msg): self
    {
        return new self($msg, self::REFUSED);
    }
}
