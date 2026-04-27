<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
//        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
//            $switch
//                ->locales(['fr', 'ar']); // also accepts a closure
//        });

        Table::$defaultDateTimeDisplayFormat = 'd.m.Y H:i';
        Table::$defaultDateDisplayFormat = 'd.m.Y';
        Table::$defaultCurrency = 'TRY';

        Infolist::$defaultCurrency = 'TRY';
        Infolist::$defaultDateTimeDisplayFormat = 'd.m.Y H:i';

        Number::useLocale('tr');
        Number::useCurrency('TRY');

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => Blade::render('
        <audio id="notification-sound" src="{{ asset(\'sounds/export-notification.wav\') }}" preload="auto"></audio>
        <script>
            // Tarayıcı autoplay kilidini aç
            document.addEventListener("click", function unlockAudio() {
                const audio = document.getElementById("notification-sound");
                audio.play().then(() => {
                    audio.pause();
                    audio.currentTime = 0;
                }).catch(() => {});
                document.removeEventListener("click", unlockAudio);
            }, { once: true });

            document.addEventListener("DOMContentLoaded", () => {
                const observer = new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                        mutation.addedNodes.forEach((node) => {
                            if (node.nodeType === 1 && node.classList && node.classList.contains("fi-no-notification")) {
                                setTimeout(() => {
                                    const title = node.querySelector("[class*=\'title\']");
                                    if (title && title.textContent.trim().includes("Tamamlandı")) {
                                        document.getElementById("notification-sound").play();
                                    }
                                }, 300);
                            }
                        });
                    });
                });

                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            });
        </script>
    '),
        );
    }
}
