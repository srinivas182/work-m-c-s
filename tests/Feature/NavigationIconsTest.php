<?php

declare(strict_types=1);

use Wurk\Platform\Modules\ModuleServiceProvider;

/*
| A menu icon that isn't registered in the design system silently renders as a
| blank circle (it happened to Billing, Failed payments and Invoice settings).
*/

it('uses only registered icons in module menus', function () {
    preg_match_all("/^\s+'?([a-z][a-z0-9-]*)'?: [A-Z]\w+,$/m", (string) file_get_contents(resource_path('js/design-system/icons.ts')), $m);
    $registered = $m[1];
    expect($registered)->not->toBeEmpty();

    $missing = [];
    foreach (app()->getProviders(ModuleServiceProvider::class) as $provider) {
        foreach ($provider->menu() as $item) {
            if (isset($item['icon']) && ! in_array($item['icon'], $registered, true)) {
                $missing[] = class_basename($provider).': '.$item['label'].' → '.$item['icon'];
            }
        }
    }

    expect($missing)->toBe([]);
});
