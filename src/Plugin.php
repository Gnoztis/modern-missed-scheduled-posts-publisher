<?php
declare(strict_types=1);

namespace ModernMSPP;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    private readonly Publisher $publisher;

    private function __construct()
    {
        $this->publisher = new Publisher();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        $this->publisher->register();
    }
}