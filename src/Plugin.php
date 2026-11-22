<?php
declare(strict_types=1);

namespace ModernMSPP;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    private readonly Publisher $publisher;

    private readonly Updater $updater;

    private function __construct()
    {
        $this->publisher = new Publisher();
        $this->updater   = new Updater();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        $this->publisher->register();
        $this->updater->register_hooks();
    }
}
