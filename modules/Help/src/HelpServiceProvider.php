<?php

declare(strict_types=1);

namespace Wurk\Help;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class HelpServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Help centre', 'route' => 'admin.help', 'icon' => 'book-open', 'section' => 'Platform', 'permission' => 'help.manage', 'order' => 60]];
    }

    public function permissions(): array
    {
        return [['key' => 'help.manage', 'label' => 'Write and publish help centre articles and videos', 'scope' => 'platform']];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'help');
    }
}
