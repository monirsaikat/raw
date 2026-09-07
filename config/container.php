<?php

// Dependency injection bindings. Anything not listed is built by
// reflection when requested (app(ReportBuilder::class) or a type-hinted
// controller constructor), so only interfaces, configured objects and
// shared services need an entry.

return [
    // Interface or name => concrete class or closure. A new object each time.
    'bindings' => [
        // 'PaymentGateway' => 'StripeGateway',
    ],

    // Built once per request, then shared.
    'singletons' => [
        // 'Mailer' => fn (Container $app) => new Mailer(config('mail')),
    ],

    // Short names for abstracts.
    'aliases' => [
        // 'gate' => 'Gate',
    ],
];
