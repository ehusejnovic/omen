<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Sends messages to the configured chat through the Telegram Bot API.
 */
class TelegramClient
{
    /**
     * Whether a bot token and chat id are configured.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    /**
     * Send an HTML formatted message to the configured chat.
     *
     * @throws RequestException|ConnectionException
     */
    public function sendMessage(string $html): void
    {
        Http::baseUrl('https://api.telegram.org/bot'.config('services.telegram.bot_token'))
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->asJson()
            ->post('/sendMessage', [
                'chat_id' => config('services.telegram.chat_id'),
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ])
            ->throw();
    }
}
