<?php

declare(strict_types=1);

namespace App;

use App\Api\ApiKernel;

/**
 * Default kernel — delegates to ApiKernel.
 *
 * Exists for backward compatibility with bin/console and Symfony tooling
 * that expects an App\Kernel class.
 */
final class Kernel extends ApiKernel
{
}
