<?php

namespace App\Providers;

use App\Services\AI\AIProvider;
use App\Services\AI\AnthropicProvider;
use App\Services\AI\FakeAIProvider;
use App\Services\Speech\FakeSpeechProvider;
use App\Services\Speech\NullSpeechProvider;
use App\Services\Speech\OpenAiCompatibleSpeechProvider;
use App\Services\Speech\SpeechToTextProvider;
use App\Services\WhatsApp\Handlers\InboundHandler;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WhatsAppProvider::class, function ($app) {
            return match (config('whatsapp.provider')) {
                'meta' => new MetaWhatsAppProvider,
                'fake' => $app->isProduction()
                    ? throw new RuntimeException('WHATSAPP_PROVIDER=fake is not allowed in production.')
                    : new FakeWhatsAppProvider,
                default => throw new RuntimeException('Unknown WHATSAPP_PROVIDER: '.config('whatsapp.provider')),
            };
        });

        $this->app->singleton(AIProvider::class, function ($app) {
            return match (config('ai.provider')) {
                'anthropic' => new AnthropicProvider,
                'fake' => $app->isProduction()
                    ? throw new RuntimeException('AI_PRIMARY_PROVIDER=fake is not allowed in production.')
                    : new FakeAIProvider,
                default => throw new RuntimeException('Unknown AI_PRIMARY_PROVIDER: '.config('ai.provider')),
            };
        });

        $this->app->singleton(SpeechToTextProvider::class, fn ($app) => match (config('stt.provider')) {
            'none' => new NullSpeechProvider,
            'openai_compatible' => new OpenAiCompatibleSpeechProvider,
            'fake' => $app->isProduction()
                ? throw new RuntimeException('STT_PROVIDER=fake is not allowed in production.')
                : new FakeSpeechProvider,
            default => throw new RuntimeException('Unknown STT_PROVIDER: '.config('stt.provider')),
        });

        $this->app->bind(InboundHandler::class, fn ($app) => $app->make(config('whatsapp.handler')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('whatsapp-webhook', fn (Request $r) => Limit::perMinute((int) config('whatsapp.webhook.throttle_per_minute'))->by($r->ip()));
    }
}
