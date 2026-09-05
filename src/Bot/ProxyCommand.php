<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

use BAGArt\ProxyOperations\ProxyOperationsModule;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Modules\Attributes\TgCommandAttribute;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotManagement\Models\TgBotOwner;
use Throwable;

/**
 * /proxy — tenant-scoped inventory card (menu_integration.md M-6 slice 2).
 * Private chats only: the workspace maps to the bot owner (1 user = 1
 * workspace, plan.md «Model»), so a group surface could leak a tenant.
 * The card carries counts only — no hosts, no credentials.
 */
#[TgCommandAttribute(name: 'proxy')]
final readonly class ProxyCommand implements TgModuleProcessorContract
{
    public const string NAME = 'proxy';

    public function __construct(
        private readonly TgSenderContract $sender,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function moduleId(): string
    {
        return ProxyOperationsModule::ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(
            sender: $context->tgSender,
            tenantContext: app(TenantContext::class),
        );
    }

    public function support(TgApiTypeDTOContract $dto, TgBotConfig $botConfig, ?string $action = null): bool
    {
        return $dto instanceof MessageTypeDTO
            && $dto->text !== null
            && TgCommandRegistry::parseCommandName($dto->text) === self::NAME;
    }

    public function isStrictOrdered(TgApiTypeDTOContract $dto, TgBotConfig $botConfig, ?string $action = null): bool
    {
        return false;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
        ?TgApiTypeDTOContract $updateDto = null,
    ): void {
        assert($dto instanceof MessageTypeDTO);

        $chatId = (int) $dto->chat->id;

        if ($dto->chat->type !== 'private') {
            $this->send($botConfig, $chatId, 'The proxy inventory is available in a private chat with me only.');

            return;
        }

        $tenantId = TgBotOwner::query()->where('bot_id', $botConfig->botId)->value('user_id');

        if (! is_int($tenantId)) {
            $this->send($botConfig, $chatId, 'This bot has no linked workspace owner, so there is no inventory to show.');

            return;
        }

        try {
            $this->tenantContext->set($tenantId);
            $text = ProxyInventorySummary::toText(ProxyInventorySummary::take());
        } catch (Throwable) {
            $text = 'Inventory is not available right now.';
        } finally {
            $this->tenantContext->forget();
        }

        $this->send($botConfig, $chatId, $text);
    }

    private function send(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $text,
        ));
    }
}
