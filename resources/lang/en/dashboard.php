<?php

return [
    'engagement_heading' => 'Engagement across recent campaigns',
    'engagement_label' => 'Open and click rate of the most recently sent campaigns, oldest on the left',
    'engagement_open_rate' => 'Open rate',
    'engagement_click_rate' => 'Click rate',
    'engagement_empty' => 'No campaign has been sent yet. The trend appears here once the first one is out.',
    'engagement_single' => 'A trend needs at least two sent campaigns. This is the first one.',
    'engagement_fresh' => 'The last campaign went out less than two days ago and is still collecting its opens. Its bar will grow, so read it against the older ones with that in mind.',
    'engagement_summary' => ':count sent campaigns, from :first to :last.',
    'engagement_campaign' => ':name, sent on :at: :openrate percent open rate, :clickrate percent click rate, :sent delivered',
    'engagement_scale' => 'Highest value in the trend: :max percent',
    'growth_scale' => 'Tallest week in the chart: :max',

    'growth_heading' => 'List growth',
    'growth_label' => 'Sign-ups and sign-offs per week',
    'growth_subscribed' => 'Sign-ups',
    'growth_unsubscribed' => 'Sign-offs',
    'growth_empty' => 'Nobody has signed up or off in the past weeks.',
    'growth_summary' => 'Over :weeks weeks: :plus sign-ups and :minus sign-offs, :net net.',
    'growth_week' => 'Week of :at: :plus sign-ups, :minus sign-offs',
    'growth_note' => 'Counted as the database stands today: sign-ups from the moment of subscribing, even where a confirmation is still pending, and sign-offs only while they hold. Somebody who left and signed up again appears only with the new sign-up.',
];
