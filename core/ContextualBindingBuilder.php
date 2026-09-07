<?php

// app()->when(ReportController::class)->needs(Cache::class)->give(RedisCache::class)

final class ContextualBindingBuilder
{
    private string $needs = '';

    public function __construct(private Container $container, private string $concrete)
    {
    }

    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    public function give(Closure|string $implementation): void
    {
        $this->container->addContextualBinding($this->concrete, $this->needs, $implementation);
    }
}
