<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ParcelTrap\Contracts\Factory;
use ParcelTrap\DTOs\TrackingDetails;
use ParcelTrap\Enums\Status;
use ParcelTrap\ParcelTrap;
use ParcelTrap\AusPost\AusPost;

it('can add the AusPost driver to ParcelTrap', function () {
    /** @var ParcelTrap $client */
    $client = $this->app->make(Factory::class);

    $client->extend('auspost_other', fn () => new AusPost(
        apiKey: 'abcdefg',
        password: 'test',
        accountNumber: 'abc123',
    ));

    expect($client)->driver(AusPost::IDENTIFIER)->toBeInstanceOf(AusPost::class)
        ->and($client)->driver('auspost_other')->toBeInstanceOf(AusPost::class);
});

it('can retrieve the AusPost driver from ParcelTrap', function () {
    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER))->toBeInstanceOf(AusPost::class);
});

it('can call `find` on the AusPost driver and handle invalid tracking ID response', function () {
    $trackingDetails =  [
        'tracking_results' => [
            [
                'tracking_id' => '7XX1000',
                'errors' => [
                    [
                        'code' => 'ESB-10001',
                        'name' => 'Invalid tracking ID',
                    ],
                ],
            ],
        ],
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
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('7XX1000'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('7XX1000')
        ->status->toEqual(Status::Not_Found)
        ->status->description()->toBe('Not Found')
        ->summary->toBe('Invalid Tracking ID: The requested consignment could not be found.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});

it('can call `find` on the AusPost driver and handle a successful response', function () {
    $trackingDetails =  [
        'tracking_results' => [
            [
                'tracking_id' => '7XX1000634011427',
                'status' => 'Delivered',
                'trackable_items' => [
                    [
                        'article_id' => '7XX1000634011427',
                        'product_type' => 'eParcel',
                        'events' => [
                            [
                                'location' => 'ALEXANDRIA NSW',
                                'description' => 'Delivered',
                                'date' => '2014-05-30T14:43:09+10:00'
                            ],
                            [
                                'location' => 'ALEXANDRIA NSW',
                                'description' => 'With Australia Post for delivery today',
                                'date' => '2014-05-30T06:08:51+10:00'
                            ],
                            [
                                'location' => 'CHULLORA NSW',
                                'description' => 'Processed through Australia Post facility',
                                'date' => '2014-05-29T19:40:19+10:00'
                            ],
                            [
                                'location' => 'SYDNEY (AU)',
                                'description' => 'Arrived at facility in destination country',
                                'date' => '2014-05-29T10:16:00+10:00'
                            ],
                            [
                                'location' => 'JOHN F. KENNEDY APT\/NEW YORK (US)',
                                'description' => 'Departed facility',
                                'date' => '2014-05-26T05:00:00+10:00'
                            ],
                            [
                                'location' => 'JOHN F. KENNEDY APT\/NEW YORK (US)',
                                'description' => 'Departed facility',
                                'date' => '2014-05-26T05:00:00+10:00'
                            ],
                            [
                                'description' => 'Shipping information approved by Australia Post',
                                'date' => '2014-05-23T14:27:15+10:00'
                            ]
                        ],
                        'status' => 'Delivered'
                    ]
                ]
            ]
        ],
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
        password: 'test',
        accountNumber: 'abc123',
        client: $httpClient,
    ));

    expect($this->app->make(Factory::class)->driver(AusPost::IDENTIFIER)->find('7XX1000634011427'))
        ->toBeInstanceOf(TrackingDetails::class)
        ->identifier->toBe('7XX1000634011427')
        ->status->toEqual(Status::Delivered)
        ->status->description()->toBe('Delivered')
        ->summary->toBe('The item or items in the shipment have been delivered.')
        ->estimatedDelivery->toBeNull()
        ->raw->toBe($trackingDetails);
});
