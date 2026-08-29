<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\TableMetric;
use Throwable;

/**
 * What every newsletter figure has in common.
 *
 * This addon already reports on itself, thoroughly: `CampaignStats` gives every
 * figure of one campaign, `CampaignReport` gives the people behind each of them,
 * and `SubscriptionGrowth` draws sign-ups against sign-offs by the week. None of
 * that is repeated here, and none of it is redefined — a second definition of
 * "open rate" in one addon is a defect this repo has already fixed three times,
 * and the rule below about where the numerator comes from exists to make sure
 * this is not the fourth.
 *
 * What these metrics add is the one thing those screens cannot give: a
 * **period** that is not a campaign and not a fixed twelve weeks, a comparison
 * against the period before it, and a place beside the revenue on a shared
 * screen. `CampaignStats` answers "how did the August newsletter do"; this
 * answers "how many mails went out in August and how many people read one".
 *
 * Four decisions shape every number here.
 *
 * **1. The storage driver does not gate these figures, and that is worth
 * stating because it looks as though it should.** `marketing.storage.driver`
 * decides where *definitions* live — mailing lists, campaigns and templates, as
 * YAML or as rows. Subscriptions, messages and their events are always in the
 * database, whatever the driver says, and the config file says so in as many
 * words. So {@see available()} asks the schema and nothing else. Where the
 * driver does matter is the **labels**: under the flat driver `marketing_lists`
 * and `marketing_campaigns` exist and stand empty, so a split that joined
 * against them for a name would show every row unlabelled on half the
 * installations. Names therefore come through the repositories, which know
 * where their own definitions live. That is what {@see listName()} and
 * {@see campaignName()} are for.
 *
 * **2. Every figure clamps to this moment.** They all answer *what happened* —
 * somebody confirmed, somebody left, a mail went out, a mail was read. The
 * widest period has no upper bound at all, and this addon's tables are full of
 * the future: a campaign scheduled for Friday, a message deferred by the
 * frequency cap. Nothing here answers *what is scheduled*, deliberately — that
 * is the dashboard's question and hiding the future from it would be the same
 * lie pointing the other way.
 *
 * **3. Time is the application's, on both sides of the fence.** Every column
 * read here goes through Laravel's own `datetime` cast, which stores and reads
 * in `config('app.timezone')`, and every writer reaches for `now()`. Insights
 * builds its `Period` from `Carbon::now()`, the same clock. No conversion
 * happens and none is wanted; an addon that stored UTC behind a cast of its own
 * would be five hours out at every period boundary on a site in Chicago.
 *
 * **4. One brand, and the metric says which.** See {@see brandColumn()}.
 */
abstract class MarketingMetric extends TableMetric
{
    public function group(): string
    {
        return __('marketing::insights.group');
    }

    // -- One brand at a time -------------------------------------------------

    /**
     * The column these tables carry their brand on.
     *
     * Declaring it is the whole of it: {@see TableMetric::inPeriod()} then
     * narrows every figure, every chart and every split at once, by exactly the
     * rules `BrandScope` applies to every model in this addon — and by the same
     * rules the rest of the addon family reads by, which is what keeps two
     * tiles side by side on one screen from meaning two different things.
     *
     * **An unresolved brand is a nought, not an absent tile**, and that is a
     * change from what this class used to do. It answered `available() === false`
     * for a multi-brand install with no brand in hand, which took all six of
     * this addon's tiles off the screen — and {@see Metric::available()} means
     * "the tables are not there, a feature is off, a sibling is missing", none
     * of which a brand nobody has picked yet is. The rows are still refused
     * (`fail closed`), so nothing sums across brands; where the install has set
     * `fail_mode` to `open`, the figure reads across them exactly as the scope
     * does.
     *
     * The old version wrapped all of this in a `try` that answered `true` to a
     * missing container binding, which is why the tiles vanished without a
     * word. {@see TableMetric::brandScoped()} asks `app()->bound('brand-context')`
     * outright instead: no brand context is an install without brands, and the
     * figure is simply not narrowed.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    // -- Handles into names --------------------------------------------------

    /**
     * What a mailing list is called, or its handle where nothing knows.
     *
     * Through the repository, never a join. The definitions may be YAML files,
     * in which case `marketing_lists` is an empty table that a join would find
     * nothing in — and the split would come out labelled with handles on every
     * flat-file installation while looking perfectly correct on the developer's.
     *
     * A broken definition file must cost a label, not the tile, hence the
     * `try`.
     */
    protected function listName(string $handle): string
    {
        try {
            $list = app(MailingListRepository::class)->find($handle);
        } catch (Throwable) {
            return $handle;
        }

        return $list !== null && $list->name !== '' ? $list->name : $handle;
    }

    /** The same for a campaign, and for the same reason. */
    protected function campaignName(string $handle): string
    {
        try {
            $campaign = app(CampaignRepository::class)->find($handle);
        } catch (Throwable) {
            return $handle;
        }

        return $campaign !== null && $campaign->name !== '' ? $campaign->name : $handle;
    }

    /** The words for a row that has no value in the dimension it is split by. */
    protected function missingLabel(string $dimension): string
    {
        return __('marketing::insights.missing.'.$dimension);
    }
}
