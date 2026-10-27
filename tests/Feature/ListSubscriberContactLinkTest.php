<?php

/**
 * The subscriber table of a list links each address to its LeadHub contact.
 *
 * Same resolution as the campaign report (see ContactLinks): over the
 * normalised address, never creating a contact, and no link at all for a
 * reader who may not open contacts. Without a contact the cell stays text.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function listRows(): array
{
    $response = test()->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.lists.show', 'newsletter'));

    return json_decode($response->getContent(), true)['props']['subscribers'] ?? [];
}

beforeEach(function (): void {
    $admin = User::make()->email('lists@example.com')->makeSuper();
    $admin->save();
    $this->actingAs($admin);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    $this->subscribe = fn (string $email) => Subscription::create([
        'list_handle' => 'newsletter',
        'email' => $email,
        'status' => Subscription::STATUS_SUBSCRIBED,
    ]);
});

it('links a subscriber to their LeadHub contact', function (): void {
    Contact::create(['email' => 'maria@example.com']);
    ($this->subscribe)('maria@example.com');

    expect(listRows()[0]['contact_url'])->toBeString()->toContain('leadhub/contacts/');
});

it('matches on the normalised address', function (): void {
    Contact::create(['email' => 'maria@example.com']);
    ($this->subscribe)('Maria@Example.com');

    expect(listRows()[0]['contact_url'])->toBeString();
});

it('gives a subscriber without a contact plain text and creates no contact', function (): void {
    ($this->subscribe)('nobody@example.com');

    expect(listRows()[0]['contact_url'])->toBeNull()
        ->and(Contact::query()->count())->toBe(0);
});

it('hands out no contact links to somebody who may not see contacts', function (): void {
    Contact::create(['email' => 'maria@example.com']);
    ($this->subscribe)('maria@example.com');

    Role::make('marketing_reader')->addPermission('view marketing')->save();
    $reader = User::make()->email('reader@example.com');
    $reader->assignRole('marketing_reader');
    $reader->save();
    $this->actingAs($reader);

    expect(listRows()[0]['contact_url'])->toBeNull();
});
