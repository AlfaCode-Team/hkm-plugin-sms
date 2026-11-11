<?php

declare(strict_types=1);

namespace Plugins\Sms;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SmsPort;
use Plugins\Sms\API\Contracts\SmsSenderContract;
use Plugins\Sms\Application\Services\SmsService;
use Plugins\Sms\Infrastructure\Config\SmsConfig;
use Plugins\Sms\Infrastructure\SmsFactory;
use Plugins\Sms\Infrastructure\SmsPortAdapter;

/**
 * Sms — wires the 'sms.delivery' domain.
 *
 * Binds the kernel's SmsPort, which had no adapter, plus a richer contract for
 * callers that need a provider id, a cost, or the mock-number mapping an OTP
 * flow uses to run without sending anything.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'sms.delivery';
    }

    /** @return list<string> module DOMAINS — mirrors module.json "requires" */
    public function requires(): array
    {
        return ['http.client'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SmsPort::class, SmsSenderContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(SmsConfig::class, static fn (): SmsConfig => SmsConfig::fromEnv());

        // The service is built INSIDE the closure, not captured eagerly, for the
        // reason the Mail plugin documents: register() runs in dependency-graph
        // order, so a port this module needs may not be bound yet at register()
        // time. Deferring to first use puts the lookup after every module in the
        // graph has registered.
        //
        // Both optional ports are resolved defensively. A project may bind no
        // logger; with SMS_TRANSPORT=log and no HttpClientPort the plugin still
        // constructs and still works, and the twilio path says what is missing
        // rather than failing to build and taking unrelated commands in the same
        // container down with it.
        $container->bind(
            SmsSenderContract::class,
            static fn (ModuleContainer $c): SmsService => SmsFactory::make(
                config: $c->make(SmsConfig::class),
                http:   $c->has(HttpClientPort::class) ? $c->make(HttpClientPort::class) : null,
                logger: $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );

        // The kernel port, for everything that only needs to send. Shares the
        // service above rather than building a second one, so a diverted mock
        // destination and a logged send look the same through either door.
        $container->bind(
            SmsPort::class,
            static fn (ModuleContainer $c): SmsPortAdapter => new SmsPortAdapter(
                $c->make(SmsSenderContract::class),
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Nothing to hook. Commands are declared in module.json; this plugin
        // registers no pipeline stage and subscribes to no event, because
        // sending an SMS is something a module asks for, never something that
        // should happen as a side effect of a request passing through.
    }
}
