<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ParcelTrap\AusPost\AusPost;
use ParcelTrap\Contracts\Factory;
use ParcelTrap\DTOs\TrackingDetails;
use ParcelTrap\Enums\Status;
use ParcelTrap\ParcelTrap;

it('can add the AusPost driver to ParcelTrap', function () {
    /** @var ParcelTrap $client */
    $client = $this->app->make(Factory::class);

    $client->extend('auspost_other', fn () => new AusPost(
        apiKey: 'abcdefg'
    ));

    expect($client)->driver(AusPost::IDENTIFIER)->toBeInstanceOf(AusPost::class)
        ->and($client)->driver('auspost_other')->toBeInstanceOf(AusPost::class);
});

it('can retrieve the AusPost driver from ParcelTrap', function () {
    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER))->toBeInstanceOf(AusPost::class);
});

it('can call `find` on the AusPost driver', function () {
    $trackingDetails = [
        'tracking_number' => 'ABCDEFG12345',
        'status' => 'transit',
        'estimated_delivery' => '2022-01-01T00:00:00+00:00',
    ];

    $httpMockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode($trackingDetails)),
    ]);

    $handlerStack = HandlerStack::create($httpMockHandler);

    $httpClient = new Client([
        'handler' => $handlerStack,
    ]);

    $this->app->make(Factory::class)->extend(AusPost::IDENTIFIER, fn () => new AusPost(
        apiKey: 'abcdefg',
        client: $httpClient,
    ));

    expect($this->app->make(Factory::class)->driver('auspost')->find('ABCDEFG12345'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('ABCDEFG12345')
        ->status->toBe(Status::In_Transit)
        ->status->description()->toBe('In Transit')
        ->summary->toBe('Package status is: In Transit')
        ->estimatedDelivery->toEqual(new DateTimeImmutable('2022-01-01T00:00:00+00:00'))
        ->raw->toBe($trackingDetails);
});
