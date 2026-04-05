<?php

declare(strict_types=1);

use App\Auth\AuthKernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (array $context) {
    return new AuthKernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
