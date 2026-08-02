<?php

return [
    'title' => 'Subscribers',
    'email' => 'Email',
    'name' => 'Name',
    'status' => 'Status',
    'subscribed_at' => 'Subscribed at',
    'frequency' => 'Frequency',
    'frequency_held_back' => 'held back',
    'frequency_held_back_hint' => 'Campaigns the frequency cap deferred until the deferral budget ran out, then discarded. This contact never received them.',
    'add' => 'Add subscriber',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'optional' => 'Optional',
    'search_placeholder' => 'Search email or name…',
    'statuses' => [
        'subscribed' => 'Subscribed',
        'pending' => 'Pending',
        'unsubscribed' => 'Unsubscribed',
        'bounced' => 'Bounced',
        'complained' => 'Complained',
    ],
    'filter' => [
        'all_statuses' => 'All statuses',
    ],
    'actions' => [
        'unsubscribe' => 'Unsubscribe',
        'delete' => 'Delete',
    ],
    'delete_confirm' => [
        'title' => 'Delete subscriber',
        'message' => 'Permanently delete this subscription? This cannot be undone.',
    ],
    'flashes' => [
        'added' => 'Subscriber added.',
        'unsubscribed' => 'Subscriber unsubscribed.',
        'deleted' => 'Subscriber deleted.',
    ],
];
