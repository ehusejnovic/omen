<?php

use App\Enums\GridGrade;
use App\Models\GridScan;
use App\Models\GridSuggestion;
use App\Services\Grid\SetupAlerts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const TelegramEndpoint = 'api.telegram.org/bottest-token/sendMessage';

function configureTelegram(): void
{
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '12345']);
}

function scanWithSetup(string $symbol, GridGrade $grade): GridScan
{
    $scan = GridScan::factory()->create();
    GridSuggestion::factory()->for($scan, 'scan')->create(['symbol' => $symbol, 'grade' => $grade, 'score' => 90]);

    return $scan;
}

it('sends a Telegram alert only for A and A+ setups', function () {
    Http::preventStrayRequests();
    Http::fake([TelegramEndpoint => Http::response(['ok' => true])]);
    configureTelegram();
    $scan = GridScan::factory()->create();
    GridSuggestion::factory()->for($scan, 'scan')->create(['symbol' => 'BTCUSDT', 'grade' => GridGrade::APlus]);
    GridSuggestion::factory()->for($scan, 'scan')->create(['symbol' => 'ETHUSDT', 'grade' => GridGrade::A]);
    GridSuggestion::factory()->for($scan, 'scan')->create(['symbol' => 'SOLUSDT', 'grade' => GridGrade::BPlus]);

    app(SetupAlerts::class)->notify($scan);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '12345'
        && $request['parse_mode'] === 'HTML'
        && str_contains($request['text'], 'A+ setup: BTCUSDT'));
    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'A setup: ETHUSDT'));
});

it('does not repeat an alert for the same symbol and grade within 24 hours', function () {
    Http::preventStrayRequests();
    Http::fake([TelegramEndpoint => Http::response(['ok' => true])]);
    configureTelegram();
    $this->freezeTime();

    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::A));
    $this->travel(23)->hours();
    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::A));

    Http::assertSentCount(1);

    $this->travel(1)->hours();
    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::A));

    Http::assertSentCount(2);
});

it('alerts again within 24 hours when the same symbol reaches another grade', function () {
    Http::preventStrayRequests();
    Http::fake([TelegramEndpoint => Http::response(['ok' => true])]);
    configureTelegram();

    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::A));
    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::APlus));

    Http::assertSentCount(2);
});

it('retries a failed alert on the next scan and reports the failure', function () {
    Http::preventStrayRequests();
    Exceptions::fake();
    Sleep::fake();
    configureTelegram();
    Http::fakeSequence(TelegramEndpoint)
        ->push(['ok' => false], 500)
        ->push(['ok' => false], 500)
        ->push(['ok' => true]);

    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::A));
    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::A));

    Http::assertSentCount(3);
    Exceptions::assertReportedCount(1);
});

it('sends nothing while Telegram is not configured', function () {
    Http::preventStrayRequests();
    config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);

    app(SetupAlerts::class)->notify(scanWithSetup('BTCUSDT', GridGrade::APlus));

    Http::assertNothingSent();
});
