<?php

namespace CubeKode\RoadRunner;

use Closure;
use Throwable;
use Illuminate\Http\Request;
use Spiral\RoadRunner\Worker;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadRunner\PSR7Client;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

class Bridge
{
    /**
     * Stores the application
     *
     * @var \Illuminate\Foundation\Application|null
     */
    private $app;

    /**
     * Stores the kernel
     *
     * @var \Illuminate\Contracts\Http\Kernel|\Laravel\Lumen\Application
     */
    private $kernel;

    private $factory;

    private $initialized = false;

    private function call(string $method): Closure
    {
        return Closure::fromCallable([$this, $method]);
    }

    private function authService($auth)
    {
        $auth->extend('session', $this->call('extendSession'));
    }

    private function extendSession($app, $name, $config)
    {
        $provider = $app['auth']->createUserProvider($config['provider']);
        $guard = new SessionGuard($name, $provider, $app['session.store'], null, $app);
        if (method_exists($guard, 'setCookieJar')) {
            $guard->setCookieJar($this->app['cookie']);
        }

        if (method_exists($guard, 'setDispatcher')) {
            $guard->setDispatcher($this->app['events']);
        }

        if (method_exists($guard, 'setRequest')) {
            $guard->setRequest($this->app->refresh('request', $guard, 'setRequest'));
        }

        return $guard;
    }

    private function sessionStore()
    {
        return $this->app['session']->driver();
    }

    private function updateStore($session)
    {
        $this->app['redirect']->setSession($session);
    }

    private function prepareKernel()
    {
        $this->app = require_once 'bootstrap/app.php';
        $this->kernel = $this->app->make(Kernel::class);
        $this->app->afterResolving('auth', $this->call('authService'));
        $this->app->extend('session.store', $this->call('sessionStore'));
        $this->app->afterResolving('session.store', $this->call('updateStore'));
    }

    public function start()
    {
        $this->prepareKernel();

        $psr7 = new PSR7Client(
            new Worker(
                new StreamRelay(STDIN, STDOUT)
            )
        );

        $this->factory = new HttpFoundationFactory();
        $this->initializeWorker($psr7);
    }

    protected function readRequest($request)
    {
        return Request::createFromBase(
            $this->factory->createRequest($request)
        );
    }

    protected function handleRequest($request)
    {
        return (new DiactorosFactory)->createResponse(
            $this->kernel->handle($request)
        );
    }

    protected function resetProviders()
    {
        if (!method_exists($this->app, 'getProvider')) {
            return;
        }

        $this->resetProvider(RedisServiceProvider::class);
        $this->resetProvider(CookieServiceProvider::class);
        $this->resetProvider(SessionServiceProvider::class);
    }

    protected function workerRoutine($psr7, $req)
    {
        $response = $this->handleRequest(
            $request = $this->readRequest($req)
        );

        $psr7->respond($response);
        $this->kernel->terminate($request, $response);
        $this->resetProviders();
    }

    protected function initializeWorker($psr7)
    {
        while ($req = $psr7->acceptRequest()) {
            try {
                $this->workerRoutine($psr7, $req);

            } catch (Throwable $exception) {
                // Silence is golden
            }
        }
    }

    protected function resetProvider($providerName)
    {
        if (!$this->app->getProvider($providerName)) {
            return;
        }

        $this->appRegister($providerName, true);
    }

    protected function appRegister($providerName, $force = false)
    {
        $this->app->register($providerName, $force);
    }
}
