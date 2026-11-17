<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Marketing\Mail\ContactMessageMail;

uses(InteractsWithIdentity::class);

/** Sprint 19.6: WURK's contact addresses are set in Admin → Security, not only in the server's settings. */
it('saves WURK’s contact addresses, which replace the server’s everywhere they’re used', function () {
    Mail::fake();
    config(['wurk.contact_email' => 'old@wurk.test', 'wurk.support_email' => 'support@wurk.test', 'wurk.security.contact' => 'security@wurk.test']);
    $admin = $this->staffWithRole('super-admin', 'admin@wurk.co.za');
    $this->actingAs($admin)->get($this->admin('/security'))->assertInertia(fn (Assert $p) => $p->where('contacts.contact_email', 'old@wurk.test'));

    $this->actingAs($admin)->put($this->admin('/security/contacts'), ['contact_email' => 'not an email', 'support_email' => 'a@b.co', 'security_email' => 'a@b.co'])->assertSessionHasErrors('contact_email');
    $this->actingAs($admin)->put($this->admin('/security/contacts'), ['contact_email' => 'Hello@WURK.co.za', 'support_email' => 'help@wurk.co.za', 'security_email' => 'trust@wurk.co.za'])
        ->assertSessionHas('status', 'WURK’s contact addresses saved.');
    config(['wurk.contact_email' => 'old@wurk.test', 'wurk.security.contact' => 'security@wurk.test']); // a fresh worker, with the server's values

    $root = 'http://'.config('wurk.domains.root');
    $this->get("{$root}/.well-known/security.txt")->assertOk()->assertSee('Contact: mailto:trust@wurk.co.za');
    $this->post("{$root}/contact", ['name' => 'Lerato', 'contact' => '082 555 0123', 'message' => 'Please call me about WURK.'])->assertSessionHasNoErrors();
    Mail::assertSent(ContactMessageMail::class, fn (ContactMessageMail $m) => $m->hasTo('hello@wurk.co.za'));

    $support = $this->staffWithRole('support', 'help2@wurk.co.za');
    $this->actingAs($support)->put($this->admin('/security/contacts'), ['contact_email' => 'x@y.co', 'support_email' => 'x@y.co', 'security_email' => 'x@y.co'])->assertForbidden();
});
