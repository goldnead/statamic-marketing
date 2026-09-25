<?php

/*
 * JSON translations are global. A Statamic word with another value changes
 * Statamic's own screens ("List" read "Verteiler" everywhere in the CP), and a
 * key named like another addon renames that addon in the addon list.
 */
it('does not rename other addons or Statamic words', function (): void {
    $own = json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/de.json'), true);
    $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

    $others = ['Accounts', 'Activity', 'Affiliates', 'App API', 'Assessments', 'Certificates', 'Courses', 'Email Templates',
        'Entitlements', 'Events', 'Inbox', 'Insights', 'Invoices', 'LeadHub', 'Lead Magnets', 'Notifications',
        'Preference Center', 'Suppression', 'Teams', 'Webhook Manager'];

    expect(array_values(array_intersect($others, array_keys($own))))->toBe([])
        ->and(array_keys(array_filter($own, fn (string $value, string $key): bool => isset($core[$key]) && $core[$key] !== $value, ARRAY_FILTER_USE_BOTH)))->toBe([]);
});
