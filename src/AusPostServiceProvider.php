<?php

declare(strict_types=1);

namespace ParcelTrap\AusPost;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use ParcelTrap\Contracts\Factory;
use ParcelTrap\ParcelTrap;

class AusPostServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var ParcelTrap $factory */
        $factory = $this->app->make(Factory::class);

        $factory->extend(AusPost::IDENTIFIER, function () {
            /** @var Repository $config */
            $config = $this->app->make(Repository::class);

            return new AusPost(
                /** @phpstan-ignore-next-line */
                apiKey: (string) $config->get('parceltrap.drivers.auspost.api_key'),
                /** @phpstan-ignore-next-line */
                password: (string) $config->get('parceltrap.drivers.auspost.password'),
                /** @phpstan-ignore-next-line */
                accountNumber: (string) $config->get('parceltrap.drivers.auspost.account_number'),
            );
        });
    }
}
