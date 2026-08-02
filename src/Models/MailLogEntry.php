<?php

namespace Goldnead\Marketing\Models;

use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Sending\DatabaseFrequencyCap;
use Illuminate\Database\Eloquent\Model;

/**
 * One line per mail that reached somebody, with the class it was sent under.
 *
 * This is what the frequency cap counts and what the control panel reads to
 * explain a hold. It is not a copy of `marketing_messages`: that table records
 * what *this addon* sent as part of a campaign, and the cap is about everything
 * that reaches a person, from any addon in the family that names a
 * {@see MailClass}.
 *
 * **No `HasBrand`, on purpose.** The trait stamps the current brand on create
 * and scopes reads to it, and both are wrong here. Every write and every read
 * happens inside a queue worker, which has no current brand — under multi-brand
 * the scope would fail closed, the count would come back 0, and the cap would
 * quietly never fire for anybody. The brand is instead passed in explicitly by
 * the caller, which has it on the message row, and `brand_id` is an ordinary
 * indexed column that {@see DatabaseFrequencyCap}
 * always states in its `where`. Isolation is kept; it is just not delegated to
 * a scope that cannot see the tenant here.
 *
 * **No `datetime` cast on `sent_at`, also on purpose.** Laravel's cast writes a
 * zoned Carbon out with its own offset dropped rather than converted, so a
 * value that arrived in a different timezone from the application's is stored
 * wrong by exactly that offset — and a rolling window built on such a value is
 * wrong by the same amount at its edges. The service writes and compares
 * through `now()` alone, one clock, no conversions, which is correct whatever
 * `app.timezone` is set to. `MarketingFrequencyCapTest` runs the whole flow in
 * Europe/Berlin to hold that.
 *
 * @property string $email_normalized
 * @property string $mail_class
 * @property int|null $brand_id
 * @property string|null $reference
 */
class MailLogEntry extends Model
{
    protected $table = 'marketing_mail_log';

    protected $guarded = [];

    public $timestamps = false;
}
