<?php

namespace Goldnead\Marketing\Support;

use Illuminate\Support\Facades\DB;

/**
 * A timestamp column, reduced to the hour or the day it falls in — in SQL.
 *
 * Every chart in this addon is a count per time bucket, and there are only two
 * places that can do the bucketing: the database, or PHP. PHP means carrying
 * every row out of the connection first, and a campaign with fifty thousand
 * recipients has hundreds of thousands of events — the one shape this addon
 * has spent three releases making sure a report never takes. So the database
 * groups, and PHP receives one row per bucket.
 *
 * The expression is a plain `substr` of the timestamp rather than any engine's
 * date function, because `DATE_FORMAT` (MySQL), `strftime` (SQLite) and
 * `date_trunc` (Postgres) share no spelling at all, and the suite runs on the
 * first two (see tests/TestCase.php and phpunit.mysql.xml). A timestamp
 * renders as `YYYY-MM-DD HH:MM:SS` on every one of them, so the first ten
 * characters are its day and the first thirteen are its hour. MySQL and SQLite
 * convert the column to text for `substr` on their own; Postgres refuses to,
 * and is given the cast it asks for.
 *
 * The buckets are therefore in the connection's timezone, which is the app's —
 * the same clock the timestamps were written on. Nothing here converts, so a
 * bucket boundary is where the reader's own day and hour begin.
 */
final class TimeBucket
{
    public const HOUR = 13;

    public const DAY = 10;

    /**
     * @param  string  $expression  a column, or any SQL expression yielding a timestamp
     * @param  int  $width  self::HOUR or self::DAY
     */
    public static function of(string $expression, int $width): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "substr(({$expression})::text, 1, {$width})"
            : "substr({$expression}, 1, {$width})";
    }
}
