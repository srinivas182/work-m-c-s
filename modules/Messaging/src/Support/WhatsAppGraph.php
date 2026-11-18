<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;

/**
 * Meta's Graph API for connecting businesses' own WhatsApp numbers (Sprint 21.1, Embedded Signup). WURK is the
 * Tech Provider: the app's ID, secret and Embedded Signup configuration are set in Admin → Providers → WhatsApp.
 */
final readonly class WhatsAppGraph
{
    /** The template a business uses to follow up after the 24-hour window. */
    public const FOLLOW_UP = WhatsAppBusiness::FOLLOW_UP;

    public const FOLLOW_UP_LANGUAGE = 'en';

    /** Its words, with the customer's name and the business's name. */
    public const FOLLOW_UP_BODY = 'Hi {{1}}, this is {{2}} following up on your message. Reply here and we’ll carry on the conversation.';

    /**
     * The templates WURK submits on every connected account for Meta's review (utility messages, in English for now).
     *
     * @var array<string, array{body: string, example: list<string>, buttons?: list<string>}>
     */
    public const TEMPLATES = [
        self::FOLLOW_UP => ['body' => self::FOLLOW_UP_BODY, 'example' => ['Thandi', 'Glow Salon']],
        WhatsAppBusiness::REMINDER => ['body' => 'Hi {{1}}, a reminder of your {{2}} at {{3}} on {{4}}. Tap below to confirm, move or cancel.',
            'example' => ['Thandi', 'Box braids', 'Glow Salon', 'Sat 21 Nov, 14:00'], 'buttons' => ['Confirm', 'Move', 'Cancel']],
        WhatsAppBusiness::PAYMENT_REQUEST => ['body' => 'Hi {{1}}, {{2}} has sent you a request to pay {{3}} for {{4}}. You can view and pay it here: {{5}} Thank you.',
            'example' => ['Thandi', 'Glow Salon', 'R1 200.00', 'INV-0003', 'https://glow-salon.wurk.co.za/invoice/01J'], ],
    ];

    public function __construct(private ProviderCredentials $providers) {}

    /** Whether WURK's Meta app is set up for businesses to connect (app ID, secret and configuration). */
    public function available(): bool
    {
        $app = $this->app();

        return $app['app_id'] !== '' && $app['app_secret'] !== '' && $app['config_id'] !== '';
    }

    /**
     * Meta's Embedded Signup dialog. `coexistence` onboards a number already used with the WhatsApp Business app.
     */
    public function signupUrl(string $redirectUri, string $state, bool $coexistence): string
    {
        $app = $this->app();
        $extras = ['setup' => (object) [], 'sessionInfoVersion' => '3'] + ($coexistence ? ['featureType' => 'whatsapp_business_app_onboarding'] : []);

        return 'https://www.facebook.com/'.$app['version'].'/dialog/oauth?'.http_build_query([
            'client_id' => $app['app_id'], 'redirect_uri' => $redirectUri, 'state' => $state, 'response_type' => 'code',
            'config_id' => $app['config_id'], 'override_default_response_type' => 'true', 'extras' => (string) json_encode($extras),
        ]);
    }

    /** Exchanges the code from the dialog for the business's token. */
    public function exchange(string $code, string $redirectUri): string
    {
        $app = $this->app();
        $token = $this->ok(Http::timeout(15)->get($this->url('oauth/access_token'), [
            'client_id' => $app['app_id'], 'client_secret' => $app['app_secret'], 'code' => $code, 'redirect_uri' => $redirectUri,
        ]))->json('access_token');

        return is_string($token) && $token !== '' ? $token : throw new RuntimeException('Meta returned no token.');
    }

    /**
     * The WhatsApp accounts the business shared with WURK (from the token's granted scopes).
     *
     * @return list<string>
     */
    public function sharedAccounts(string $token): array
    {
        $app = $this->app();
        $scopes = (array) $this->ok(Http::timeout(15)->get($this->url('debug_token'), [
            'input_token' => $token, 'access_token' => $app['app_id'].'|'.$app['app_secret'],
        ]))->json('data.granular_scopes', []);
        $ids = [];
        foreach ($scopes as $scope) {
            if (in_array($scope['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                array_push($ids, ...array_map('strval', (array) ($scope['target_ids'] ?? [])));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The account's phone numbers, with what WURK shows the owner.
     *
     * @return list<array{id: string, display_phone: string|null, verified_name: string|null, quality: string|null, messaging_limit: string|null, name_status: string|null, platform: string|null}>
     */
    public function phoneNumbers(string $wabaId, string $token): array
    {
        $rows = (array) $this->ok(Http::withToken($token)->timeout(15)->get($this->url($wabaId.'/phone_numbers'), [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier,name_status,platform_type',
        ]))->json('data', []);

        return array_values(array_map(fn (array $r) => [
            'id' => (string) $r['id'], 'display_phone' => $r['display_phone_number'] ?? null, 'verified_name' => $r['verified_name'] ?? null,
            'quality' => $r['quality_rating'] ?? null, 'messaging_limit' => $r['messaging_limit_tier'] ?? null,
            'name_status' => $r['name_status'] ?? null, 'platform' => $r['platform_type'] ?? null,
        ], array_filter($rows, 'is_array')));
    }

    /** Subscribes WURK's app to the account's webhooks (messages and statuses come to WURK). */
    public function subscribe(string $wabaId, string $token): void
    {
        $this->ok(Http::withToken($token)->timeout(15)->post($this->url($wabaId.'/subscribed_apps')));
    }

    public function unsubscribe(string $wabaId, string $token): void
    {
        $this->ok(Http::withToken($token)->timeout(15)->delete($this->url($wabaId.'/subscribed_apps')));
    }

    /** Registers a new number for the Cloud API, with a two-step verification PIN (not needed for coexistence). */
    public function register(string $phoneNumberId, string $token, string $pin): void
    {
        $this->ok(Http::withToken($token)->timeout(15)->post($this->url($phoneNumberId.'/register'), ['messaging_product' => 'whatsapp', 'pin' => $pin]));
    }

    /**
     * Submits WURK's templates that the account doesn't have yet (Meta keeps them), and returns every one's review
     * status by name: PENDING, APPROVED, REJECTED or PAUSED.
     *
     * @return array<string, string>
     */
    public function ensureTemplates(string $wabaId, string $token): array
    {
        $statuses = $this->templateStatuses($wabaId, $token);
        foreach (self::TEMPLATES as $name => $t) {
            if (isset($statuses[$name])) {
                continue;
            }
            $components = [['type' => 'BODY', 'text' => $t['body'], 'example' => ['body_text' => [$t['example']]]]];
            if (isset($t['buttons'])) {
                $components[] = ['type' => 'BUTTONS', 'buttons' => array_map(fn (string $b) => ['type' => 'QUICK_REPLY', 'text' => $b], $t['buttons'])];
            }
            $response = $this->ok(Http::withToken($token)->timeout(15)->post($this->url($wabaId.'/message_templates'), [
                'name' => $name, 'language' => self::FOLLOW_UP_LANGUAGE, 'category' => 'UTILITY', 'components' => $components,
            ]));
            $statuses[$name] = strtoupper((string) ($response->json('status') ?: 'PENDING'));
        }

        return array_intersect_key($statuses, self::TEMPLATES);
    }

    /**
     * WURK's templates on the account (in English) and their review status, by name.
     *
     * @return array<string, string>
     */
    public function templateStatuses(string $wabaId, string $token): array
    {
        $rows = (array) $this->ok(Http::withToken($token)->timeout(15)->get($this->url($wabaId.'/message_templates'), [
            'fields' => 'name,status,language', 'limit' => 200,
        ]))->json('data', []);
        $statuses = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset(self::TEMPLATES[$row['name'] ?? '']) && ($row['language'] ?? self::FOLLOW_UP_LANGUAGE) === self::FOLLOW_UP_LANGUAGE) {
                $statuses[(string) $row['name']] = strtoupper((string) ($row['status'] ?? 'PENDING'));
            }
        }

        return $statuses;
    }

    /** @return array{app_id: string, app_secret: string, config_id: string, version: string} */
    private function app(): array
    {
        $provider = $this->providers->active('whatsapp');

        return [
            'app_id' => (string) ($provider['settings']['app_id'] ?? ''),
            'app_secret' => (string) ($provider['credentials']['app_secret'] ?? ''),
            'config_id' => (string) ($provider['settings']['signup_config_id'] ?? ''),
            'version' => (string) (($provider['settings']['api_version'] ?? '') ?: MetaCloudWhatsApp::DEFAULT_VERSION),
        ];
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->app()['version'].'/'.$path;
    }

    private function ok(Response $response): Response
    {
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error.message') ?? 'Meta answered '.$response->status()));
        }

        return $response;
    }
}
