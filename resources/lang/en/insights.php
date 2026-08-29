<?php

return [
    'group' => 'Newsletter',

    'subscribed' => 'Confirmed sign-ups',
    'subscribed_description' => 'Subscriptions confirmed in the period, by the day consent was given.',

    'unsubscribed' => 'Unsubscribes',
    'unsubscribed_description' => 'Subscriptions ended in the period, by the day the person left.',

    'subscribers_active' => 'Subscribers',
    'subscribers_active_description' => 'Confirmed subscriptions still standing at the end of the period.',

    'mails_sent' => 'Mails sent',
    'mails_sent_description' => 'Individual mails handed to the relay in the period. Ten thousand recipients of one newsletter are ten thousand mails.',

    'open_rate' => 'Open rate',
    'open_rate_description' => 'How many of the mails sent were opened by a person. Machine opens do not count.',

    'click_rate' => 'Click rate',
    'click_rate_description' => 'How many of the mails sent had a link followed.',

    'breakdown' => [
        'list' => 'List',
        'campaign' => 'Campaign',
    ],

    'missing' => [
        'list_handle' => 'No list',
        'campaign_handle' => 'Outside any campaign',
    ],
];
