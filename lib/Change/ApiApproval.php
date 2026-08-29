<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

/**
 * What holds when an API caller approves its own change requests.
 *
 * ## Why there is nothing to configure here
 *
 * This started as a settings page: switch the feature on, pick allowed types,
 * pick allowed operations, restrict to a category, require the target to stay
 * offline, cap the batch. All of it was removed, and the reasoning is worth
 * keeping because it will look like an omission otherwise.
 *
 * **The scope is the decision.** Granting `ai_platform/changes/approve` to a
 * token is a deliberate act performed in the api addon's token administration by
 * someone who had to find the setting and tick it. Asking the same question a
 * second time on another page does not add a safeguard; it adds a place where
 * the two answers can disagree — a token holding the scope while the feature
 * reads "off" produces a 403 that looks like a bug and takes a while to trace.
 *
 * Everything the allow list used to restrict was a guess about what the operator
 * wanted. Whoever hands out an approval scope wants approvals: creating,
 * updating and deleting, published or not, wherever the content lives. Narrowing
 * that by default only meant the first real use ran into a refusal and someone
 * had to widen it.
 *
 * The remaining boundary is the scope itself, plus the two invariants below —
 * and `changes_enabled`, which switches off change requests entirely and is the
 * kill switch if one is ever needed.
 *
 * ## The two invariants
 *
 * These are not settings and never were, because neither has a sensible "off":
 *
 * 1. **Only the caller's own requests.** Approving someone else's proposal means
 *    deciding it — a judgement the caller was never asked for. A token that
 *    could wave through a proposal a person filed and is still thinking about
 *    would be making editorial decisions by accident.
 * 2. **No `force`.** A stale target, a deleted target or a broken reference is
 *    exactly the situation that needs someone to look. The caller cannot know
 *    what moved in the meantime, so `ChangeService::approveViaApi()` has no
 *    force parameter at all rather than one defaulting to false.
 *
 * Every API approval is recorded with `reviewed_via = 'api'` and shown in the
 * inbox as decided without review. That is the actual control: not preventing
 * the write, but making it impossible to miss afterwards.
 */
final class ApiApproval
{
    /** Scope a token needs to approve its own requests. */
    public const SCOPE = 'ai_platform/changes/approve';

    /**
     * Why this caller may not approve this request, or null when it may.
     *
     * @param ChangeRequest $request The request being decided
     * @param Source $source Identity derived from the token, never from the body
     */
    public static function refusalReason(ChangeRequest $request, Source $source): ?string
    {
        if ($request->source->key !== $source->key) {
            return 'A caller may only approve change requests it submitted itself.';
        }

        return null;
    }

    /**
     * The rules, for `/types` — so a caller reads them instead of discovering
     * them one refusal at a time.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return [
            'scope' => self::SCOPE,
            'own_requests_only' => true,
            'force_available' => false,
            'recorded_as' => 'reviewed_via=api',
        ];
    }

}
