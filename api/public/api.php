<?php

declare(strict_types=1);

use App\Api\ApiKernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (array $context) {
    return new ApiKernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
