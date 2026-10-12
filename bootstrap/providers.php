<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\IdentityAccessServiceProvider;
use App\Providers\OrganizationServiceProvider;
use App\Providers\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    OrganizationServiceProvider::class,
    IdentityAccessServiceProvider::class,
    FortifyServiceProvider::class,
];
