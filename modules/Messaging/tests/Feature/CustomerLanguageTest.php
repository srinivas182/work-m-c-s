<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Platform\I18n\TranslationStore;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 24.2: messages to customers come in the customer's language — the standard wording translated (reviewed or
 * drafted), the business's own wording as they wrote it — and the language is remembered from the page they used.
 */

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
});

it('sends the standard wording in the customer’s language, keeping {placeholders}', function () {
    expect(TranslationStore::save('zu', 'Hi {client}, see you at {when}.', 'Sawubona, sizokubona ngo-{when}.', 'reviewed', null))->toBeFalse(); // {client} lost
    TranslationStore::save('zu', 'Hi {client}, see you at {when}.', 'Sawubona {client}, sizokubona ngo-{when}.', 'reviewed', null);
    ($this->in)(function () {
        $t = app(MessageTemplates::class);
        $t->define('test.reminder', ['group' => 'Tests', 'label' => 'Reminder', 'placeholders' => ['client', 'when'], 'required' => [], 'subject' => 'Reminder',
            'text' => 'Hi {client}, see you at {when}.', 'sample' => ['client' => 'Thandi', 'when' => '10:00'], 'channels' => ['sms']]);
        expect($t->text('test.reminder', 'sms', ['client' => 'Thandi', 'when' => '10:00'], 'zu'))->toBe('Sawubona Thandi, sizokubona ngo-10:00.')
            ->and($t->text('test.reminder', 'sms', ['client' => 'Thandi', 'when' => '10:00'], 'fr'))->toBe('Hi Thandi, see you at 10:00.'); // not translated yet: English
        $t->save('test.reminder', 'sms', null, 'Hello {client}! {when} it is.', 'owner');
        expect($t->text('test.reminder', 'sms', ['client' => 'Thandi', 'when' => '10:00'], 'zu'))->toBe('Hello Thandi! 10:00 it is.'); // the business's own words
    });
});

it('remembers a customer’s language from the customer page they used', function () {
    ($this->in)(function () {
        app()->setLocale('fr');
        request()->attributes->set('wurk.customer_page', true);
        $id = app(ContactBook::class)->remember('Amélie', '0825550101', null, 'booking', '01J0000000000000000000BOOK', null, 'Booking');
        app()->setLocale('en');
        request()->attributes->remove('wurk.customer_page');
        expect(app(ContactBook::class)->locale('+27825550101'))->toBe('fr');
        app(ContactBook::class)->setLocale($id, 'xx');
        expect(app(ContactBook::class)->locale('+27825550101'))->toBeNull();
    });
});
