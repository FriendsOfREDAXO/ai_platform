<?php

use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use PHPUnit\Framework\TestCase;

/**
 * ChangeRequest's with*() copies pass every constructor argument by position.
 *
 * When reviewedVia was added neither copy was updated, every later field moved
 * one slot and editing a proposal in the backend died with a TypeError.
 *
 * @internal
 */
final class ai_platform_change_request_test extends TestCase
{
    private function request(): ChangeRequest
    {
        return new ChangeRequest(
            id: 7,
            type: 'article',
            operation: ChangeOperation::Update,
            target: ArticleTarget::existing(1, rex_clang::getStartId()),
            payload: (new ArticlePayload())->priority(2),
            payloadEdited: null,
            snapshotBefore: ['priority' => 1],
            baseHash: 'hash',
            contextSnapshot: null,
            contextHash: null,
            reason: 'test',
            status: ChangeStatus::Pending,
            source: Source::agent('test:unit'),
            changesetId: 3,
            targetLabel: 'label',
            reviewedBy: 11,
            reviewedAt: new DateTimeImmutable('2026-01-02 03:04:05'),
            reviewNote: 'note',
            reviewedVia: 'backend',
            appliedAt: new DateTimeImmutable('2026-01-03 03:04:05'),
            applyResult: ['ok' => true],
            applyError: 'error',
            createdAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }

    public function testWithPayloadEditedKeepsEveryOtherField(): void
    {
        $original = $this->request();
        $copy = $original->withPayloadEdited((new ArticlePayload())->priority(5));

        $edited = $copy->effectivePayload();
        $proposed = $copy->payload;
        self::assertInstanceOf(ArticlePayload::class, $edited);
        self::assertInstanceOf(ArticlePayload::class, $proposed);
        self::assertSame(5, $edited->get('priority'));
        self::assertSame(2, $proposed->get('priority'), 'the original proposal is never overwritten');
        $this->assertSameExcept($original, $copy, ['payloadEdited']);
    }

    public function testWithStatusKeepsEveryOtherField(): void
    {
        $original = $this->request();
        $copy = $original->withStatus(ChangeStatus::Rejected);

        self::assertSame(ChangeStatus::Rejected, $copy->status);
        $this->assertSameExcept($original, $copy, ['status']);
    }

    /**
     * @param list<string> $changed
     */
    private function assertSameExcept(ChangeRequest $original, ChangeRequest $copy, array $changed): void
    {
        foreach ((new ReflectionClass(ChangeRequest::class))->getProperties() as $property) {
            if (in_array($property->getName(), $changed, true)) {
                continue;
            }
            self::assertSame($property->getValue($original), $property->getValue($copy), $property->getName());
        }
    }
}
