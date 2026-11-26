<?php

namespace app\components\legacy;

use RuntimeException;

/** Messages are safe to log: never data, SQL or credentials. */
final class LegacyImportException extends RuntimeException
{
}
