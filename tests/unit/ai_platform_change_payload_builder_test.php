<?php

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ArrayBuildableHandlerInterface;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangePayloadBuilder;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\CategoryPayload;
use FriendsOfRedaxo\AiPlatform\Change\PayloadInterface;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use PHPUnit\Framework\TestCase;

/**
 * Types registered by other addons reach ChangePayloadBuilder through
 * ArrayBuildableHandlerInterface, and only with a target and payload of
 * their own.
 *
 * @internal
 */
final class ai_platform_change_payload_builder_test extends TestCase
{
    public function testDelegatesToHandlerWithNormalisedFields(): void
    {
        $received = null;
        $handler = $this->buildableHandler('article', static function (ChangeOperation $operation, array $target, array $fields) use (&$received): array {
            $received = [$operation, $target, $fields];

            return [
                'target' => ArticleTarget::existing((int) $target['article_id'], rex_clang::getStartId()),
                'payload' => (new ArticlePayload())->name($fields['name']),
            ];
        });

        $built = ChangePayloadBuilder::build(
            $handler,
            ChangeOperation::Update,
            ['article_id' => 5],
            ['name' => 'Neu', 'empty' => '  ', 'missing' => null, 'number' => 3],
        );

        self::assertSame(ChangeOperation::Update, $received[0]);
        self::assertSame(['article_id' => 5], $received[1]);
        // Empty values are dropped and scalars arrive as strings, exactly as
        // for the built-in types.
        self::assertSame(['name' => 'Neu', 'number' => '3'], $received[2]);

        self::assertInstanceOf(ArticleTarget::class, $built['target']);
        self::assertSame(['name' => 'Neu'], $built['payload']->toArray());
    }

    public function testRejectsTargetOfAnotherType(): void
    {
        // The service resolves the handler from the target, so an article
        // target would be validated and applied by the article handler.
        $handler = $this->buildableHandler('note', static fn (): array => [
            'target' => ArticleTarget::existing(5, rex_clang::getStartId()),
            'payload' => (new ArticlePayload())->name('x'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Handler "note" built a target of type "article".');

        ChangePayloadBuilder::build($handler, ChangeOperation::Update, [], ['name' => 'x']);
    }

    public function testRejectsPayloadOfAnotherClass(): void
    {
        $handler = $this->buildableHandler('article', static fn (): array => [
            'target' => ArticleTarget::existing(5, rex_clang::getStartId()),
            'payload' => (new CategoryPayload())->name('x'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('instead of a ' . ArticlePayload::class);

        ChangePayloadBuilder::build($handler, ChangeOperation::Update, [], ['name' => 'x']);
    }

    public function testUnknownTypeWithoutInterfaceNamesTheInterface(): void
    {
        $handler = new class extends AbstractHandler {
            use ai_platform_change_payload_builder_test_stub;

            public function getType(): string
            {
                return 'note';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ArrayBuildableHandlerInterface::class);

        ChangePayloadBuilder::build($handler, ChangeOperation::Update, [], ['name' => 'x']);
    }

    /**
     * @param Closure(ChangeOperation, array<string, mixed>, array<string, string>): array{target: TargetInterface, payload: PayloadInterface} $build
     */
    private function buildableHandler(string $type, Closure $build): ArrayBuildableHandlerInterface
    {
        return new class($type, $build) extends AbstractHandler implements ArrayBuildableHandlerInterface {
            use ai_platform_change_payload_builder_test_stub;

            public function __construct(
                private readonly string $type,
                private readonly Closure $build,
            ) {}

            public function getType(): string
            {
                return $this->type;
            }

            public function buildFromArray(ChangeOperation $operation, array $target, array $fields): array
            {
                return ($this->build)($operation, $target, $fields);
            }

            public function describeFields(): array
            {
                return ['target' => ['article_id'], 'fields' => ['name' => 'Name']];
            }
        };
    }
}

/**
 * The handler methods build() never calls.
 *
 * @internal
 */
trait ai_platform_change_payload_builder_test_stub
{
    public function targetClass(): string
    {
        return ArticleTarget::class;
    }

    public function payloadClass(): string
    {
        return ArticlePayload::class;
    }

    public function readCurrent(TargetInterface $target): ?array
    {
        return null;
    }

    public function validate(ChangeRequest $request): void {}

    public function canApprove(rex_user $user, ChangeRequest $request): bool
    {
        return false;
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        return ApplyResult::failed('not implemented');
    }
}
