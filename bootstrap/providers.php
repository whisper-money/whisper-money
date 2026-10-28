<?php

use App\Providers\ApiDocumentationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    ApiDocumentationServiceProvider::class,
    FortifyServiceProvider::class,
];
