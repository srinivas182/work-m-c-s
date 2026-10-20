<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Design system showcase
    |--------------------------------------------------------------------------
    | The /_design page shows every token and component for visual sign-off.
    | Enabled everywhere except production unless explicitly overridden.
    */

    'design_showcase' => (bool) env('WURK_DESIGN_SHOWCASE', env('APP_ENV', 'production') !== 'production'),

];
