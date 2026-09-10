<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Api;

use FriendsOfRedaxo\Api\Auth\BearerAuth;
use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\Api\Token;
use FriendsOfRedaxo\AiPlatform\Change\ApiApproval;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangePayloadBuilder;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use JsonException;
use rex;
use rex_dir;
use rex_file;
use rex_path;
use rex_config;
use rex_sql;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Throwable;

use function count;
use function is_array;
use function is_string;

/**
 * REST routes for change requests, registered with the api addon.
 *
 * This is the adapter for the one caller the other three cannot serve: an agent
 * that lives outside this REDAXO and holds nothing but a bearer token. The PHP
 * API needs code on the server, the agent tools need an agent the CMS itself
 * started, and the backend form needs a human with a session.
 *
 * **Not MCP.** The `/mcp` endpoint carries a project's own content and the tools
 * that project chooses to publish — not the CMS's internals. Change requests are
 * CMS internals. See the "What the MCP server is for" section in CLAUDE.md; the
 * decision is deliberate and does not change just because a tool registration
 * would be short.
 *
 * Three things about this class that are load-bearing:
 *
 * 1. **It does not extend `FriendsOfRedaxo\Api\RoutePackage`.** That base class
 *    holds a single empty method, and inheriting from it would make this file
 *    unloadable whenever the api addon is absent. Since `boot.php` calls
 *    `loadRoutes()` directly (see 2), there is nothing to inherit.
 *
 * 2. **`boot.php` calls `loadRoutes()` directly instead of
 *    `RouteCollection::registerRoutePackage($this)`.** `RouteCollection::getRoutes()`
 *    sets `$packagesLoaded = true` and then never loads packages again — so a
 *    package registered after any other addon has read the route list is
 *    silently dropped. `registerRoute()` writes into the static array
 *    immediately and is immune to that ordering.
 *
 * 3. **`source_key` comes from the token, never from the request body.** The key
 *    drives the inbox filter, the per-source counts and the acceptance
 *    statistics. A caller free to name its own source hides its own history by
 *    inventing a name, and the origin the reviewer reads stops meaning
 *    anything.
 *
 * **Five of the six routes write nothing.** They end in a proposal a human has
 * to approve, which is why they are gated by scope alone: the permission check
 * belongs to the approval and lives in `HandlerInterface::canApprove()`.
 *
 * `POST /approvals` is the exception and the one route to read carefully. It
 * approves — that is, it writes — on the authority of a token, with no REDAXO
 * user behind it and therefore without `canApprove()`. What bounds it is the
 * scope itself: handing out `ai_platform/changes/approve` is the deliberate act,
 * and {@see ApiApproval} explains why there is no second switch asking the same
 * question. Every such approval is stored with `reviewed_via = 'api'`, so the
 * inbox shows an editor exactly what went through unattended.
 */
final class ChangeRoutes
{
    /** Scope prefix. Also the path prefix, minus the api addon's own `/api`. */
    private const PREFIX = 'ai_platform/changes';

    /**
     * Registers all six routes.
     *
     * `{id}` carries a `\d+` requirement, so `describe` can never be swallowed
     * by it. The explicit requirement is what makes that true — without it,
     * `/changes/describe` would match the id route and fail with "not found" for
     * a plausible id.
     *
     * ## Six routes, not eight
     *
     * The api addon authorises per route: `BearerAuth` checks
     * `in_array($parameters['_route'], $token->getScopes())`. **One route is one
     * scope**, which is the finest granularity available and the reason this file
     * has more entry points than a hand-rolled API would. Merging two routes
     * always merges two permissions.
     *
     * That trade is worth it where the permissions genuinely differ — proposing,
     * deleting and approving must stay separable, and `operation: "delete"` on
     * the propose route is refused with a pointer to `/deletions` for exactly
     * that reason. It was **not** worth it twice:
     *
     * - `list` + `get` were one privilege in two scopes. Both are read-only and
     *   both filter hard on the calling token's `source_key`; there is no
     *   situation in which you would grant one and refuse the other. Now one
     *   route with an optional `{id}`.
     * - `types` + `read` both answered "what is there". Now one route,
     *   `/describe`: without parameters the catalogue of types and fields, with
     *   `type` + `target` the current state of one target.
     *
     * So there are four privilege levels — look, propose, offer bytes, decide —
     * and seven routes rather than nine. No aliases were left behind for the old
     * paths: a route that still answers is a route somebody keeps using.
     *
     * `/uploads` is the fourth level and genuinely separate: accepting bytes is
     * not the same privilege as proposing a field value, and a token that may
     * suggest a headline has no business filling a disk.
     */
    public function loadRoutes(): void
    {
        $this->registerDescribe();
        $this->registerRequests();
        $this->registerUploads();
        $this->registerPropose();
        $this->registerDeletions();
        $this->registerApprovals();
        $this->registerWithdrawals();
    }

    // ------------------------------------------------------------------ routes

    private function registerDescribe(): void
    {
        RouteCollection::registerRoute(
            self::PREFIX . '/read',
            new Route(
                self::PREFIX . '/describe',
                [
                    '_controller' => self::class . '::handleDescribe',
                    'query' => [
                        'type' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'Change type to inspect, e.g. "slice". Omit it to get the '
                                . 'catalogue of all types and field names instead of one target\'s state. '
                                . 'Valid values come from the catalogue itself; on a typical site they are '
                                . 'slice, article, category, meta, media and yform.',
                        ],
                        'target' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'JSON object addressing one existing thing, e.g. '
                                . '{"article_id":12,"clang_id":1,"slice_id":345}. Which keys a type needs is '
                                . 'listed as "target_fields" in the catalogue. Required together with "type" — '
                                . '"type" without "target" answers 400 rather than falling back to the catalogue.',
                        ],
                    ],
                ],
                [],
                [],
                '',
                [],
                ['GET'],
            ),
            'AI PLATFORM CHANGE REQUESTS — start here. Everything under /api/ai_platform/changes is a '
            . 'proposal queue, not a write API: a call only *asks* for a change to REDAXO content '
            . '(article, category, content slice, media file, metainfo field, YForm dataset). A human '
            . 'editor sees the proposal with a field-level diff in the REDAXO backend and approves or '
            . 'rejects it; nothing reaches the website before that. The one exception is '
            . 'POST /changes/approvals, which needs its own scope. '
            . 'Whole workflow: GET /changes/describe (here) -> POST /changes (propose) -> '
            . 'POST /changes/approvals if allowed, otherwise wait -> GET /changes to see the outcome. '
            . 'This endpoint answers two questions. Without parameters: which change types this '
            . 'installation offers, which operations each supports, and the exact field names that may be '
            . 'set — metainfo fields and the allowed YForm tables differ per site, so read it before the '
            . 'first proposal instead of guessing names. Target field names are marked "(create)" or '
            . '"(update/delete)" where they differ: a category create needs parent_id, an update needs '
            . 'category_id. For the slice type the catalogue additionally carries "modules": every module '
            . 'with the value/media slots it actually reads, its labels where the project supplied them, '
            . 'and an "executes_php" flag for modules whose slice values run as PHP — those are refused, '
            . 'and it is better to see that here than in a rejection. Use it: the flat slot list says '
            . 'value1..value20 exist, "modules" says which two the module in front of you reads. '
            . 'With "type" and "target": the current values of that one target, so a proposal can be made '
            . 'against a known old state. Reads only, changes nothing, safe to call as often as needed. '
            . 'ONE THING TO KNOW ABOUT SCOPE: this token may also hold routes of the api addon that write '
            . 'REDAXO content directly and immediately (e.g. POST /api/structure/categories). Those are a '
            . 'different mechanism with no review step. Use them when an unreviewed write is what is '
            . 'wanted; use the endpoints described here when a person should decide first — and for media '
            . 'files, which only this route family can carry.',
            null,
            new BearerAuth(),
        );
    }

    private function registerRequests(): void
    {
        RouteCollection::registerRoute(
            self::PREFIX . '/requests',
            new Route(
                self::PREFIX . '/{id}',
                [
                    '_controller' => self::class . '::handleRequests',
                    // The default is what makes the segment optional, so one
                    // route serves `/changes` and `/changes/42`.
                    'id' => null,
                    'query' => [
                        'status' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'List mode only. Filter by status: pending (waiting for a '
                                . 'decision), approved, applied (written to the site), rejected, withdrawn '
                                . '(taken back by the submitter), superseded (a newer proposal for the same '
                                . 'target replaced it), expired (the target is gone), failed.',
                        ],
                        'page' => [
                            'type' => 'int',
                            'required' => false,
                            'default' => 1,
                            'description' => 'List mode only. Page number, 1-based.',
                        ],
                        'per_page' => [
                            'type' => 'int',
                            'required' => false,
                            'default' => 50,
                            'description' => 'List mode only. Requests per page.',
                        ],
                    ],
                ],
                ['id' => '\d+'],
                [],
                '',
                [],
                ['GET'],
            ),
            'AI PLATFORM CHANGE REQUESTS — check what happened to proposals this token submitted. The '
            . '{id} path segment is optional: without it a paginated list, with it (e.g. '
            . 'GET /api/ai_platform/changes/4711) that single request in full. The single view is the '
            . 'useful one after a refusal: it carries the reviewer\'s note explaining why it was rejected, '
            . 'and while the request is still pending a situation report saying whether the world moved '
            . 'since it was proposed — target deleted, its fields edited by hand meanwhile, or a '
            . 'referenced media file or link target now missing. Read that before resubmitting, otherwise '
            . 'the same proposal is refused for the same reason. Scoped to the calling token: a token only '
            . 'ever sees its own requests, never another token\'s. Reads only, changes nothing.',
            null,
            new BearerAuth(),
        );
    }

    private function registerUploads(): void
    {
        RouteCollection::registerRoute(
            // Scope `upload`, path `/uploads`. The scopes read as verbs
            // (`propose`, `approve`, `withdraw`) while the paths are the
            // collections they act on (`/deletions`, `/approvals`,
            // `/withdrawals`) — the two names are not supposed to match.
            self::PREFIX . '/upload',
            new Route(
                self::PREFIX . '/uploads',
                [
                    '_controller' => self::class . '::handleUpload',
                    // Documented for the OpenAPI page and for /api/me. Both
                    // transports are optional on their own — which one is
                    // required depends on the other, and that cannot be
                    // expressed as a per-parameter flag, so the handler decides.
                    'query' => [
                        'filename' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'File name including extension, e.g. "chart.png". Required when '
                                . 'sending raw bytes as the body, because a name cannot be guessed from bytes. '
                                . 'Optional with multipart/form-data, where the part\'s own name is used.',
                        ],
                    ],
                    'Body' => [
                        'file' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'The file itself, as a multipart/form-data part named "file". '
                                . 'Alternatively send the raw bytes as the whole request body and pass '
                                . '?filename= instead.',
                        ],
                    ],
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            'AI PLATFORM CHANGE REQUESTS — step 1 of 2 for getting a new file into the REDAXO media pool. '
            . 'This endpoint only parks the bytes and reserves a file name; the file is NOT in the media '
            . 'pool afterwards and has no public URL. It waits outside the web root until a media '
            . 'proposal referencing it is approved. '
            . 'Send either multipart/form-data with a part named "file", or the raw bytes as the request '
            . 'body plus ?filename=<name>. Limits: 32 MB, and the extension must match the real MIME type '
            . 'and be allowed by the media pool — a PHP file named .jpg is refused here, not later. '
            . 'Returns {"upload":"up_<32 hex>","filename":"<reserved name>","bytes":…,"width":…,"height":…}. '
            . 'The returned filename is reserved for this caller and must be used verbatim as '
            . 'target.filename in step 2; a different name is refused rather than silently corrected. '
            . 'The reservation lapses after 24 hours if no proposal points at it. '
            . 'Step 2 is POST /api/ai_platform/changes with '
            . '{"type":"media","operation":"create","target":{"category_id":<media category id>,'
            . '"filename":"<reserved name>"},"fields":{"upload":"up_…","title":"…"}}. '
            . 'The media category is a folder in the media pool, unrelated to the article structure: 0 is '
            . 'the root and is the safe default when no category obviously fits. Existing ones can be '
            . 'listed via GET /api/media/category if this token holds that scope — picking one at random '
            . 'files the image somewhere an editor will not look for it. '
            . 'Note the ordering trap: a content slice that displays this image can only be proposed once '
            . 'the media proposal has been APPROVED, because media references are checked at submission '
            . 'time. Alt text and copyright are metainfo, proposed separately as type "meta" with '
            . 'carrier "media".',
            null,
            new BearerAuth(),
        );
    }

    private function registerPropose(): void
    {
        RouteCollection::registerRoute(
            self::PREFIX . '/propose',
            new Route(
                self::PREFIX,
                [
                    '_controller' => self::class . '::handlePropose',
                    // Documented for the OpenAPI page. Validation happens in the
                    // handler rather than through getQuerySet(), because the body
                    // is either one proposal or a `proposals` array and a single
                    // required-field definition cannot express both. That is also
                    // why every entry says "required: false" while the text names
                    // what is actually needed in each form.
                    'Body' => [
                        'type' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'What kind of thing to change: on a typical site slice, article, '
                                . 'category, meta, media or yform. Required in the single-proposal form. Call '
                                . 'GET /api/ai_platform/changes/describe for the list valid here — it differs '
                                . 'per installation.',
                        ],
                        'operation' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => 'update',
                            'description' => '"create" to add something new, "update" to change something '
                                . 'existing. Defaults to "update". "delete" is refused here on purpose: '
                                . 'deletions go to POST /api/ai_platform/changes/deletions, which has its own '
                                . 'scope.',
                        ],
                        'target' => [
                            'type' => 'object',
                            'required' => false,
                            'default' => null,
                            'description' => 'Which thing to change, as an object. The keys differ per type '
                                . 'AND per operation, and /describe marks which is which. Update addresses '
                                . 'what exists ({"article_id":12,"clang_id":1,"slice_id":345}); create '
                                . 'addresses where the new thing goes '
                                . '({"article_id":12,"clang_id":1,"module_id":6,"ctype_id":1,"priority":2} for a '
                                . 'slice, {"parent_id":40,"clang_id":1} for a category, '
                                . '{"category_id":40,"clang_id":1} for an article). On a slice create: '
                                . '"module_id" decides which module renders the content and therefore which '
                                . 'slots are meaningful (see "modules" in /describe); "ctype_id" is the content '
                                . 'area of the template, which is 1 unless the template defines several; '
                                . '"priority" is the 1-based position among the existing slices, where 1 means '
                                . 'first and an existing slice at that position is pushed down rather than '
                                . 'overwritten. Required in the single-proposal form.',
                        ],
                        'fields' => [
                            'type' => 'object',
                            'required' => false,
                            'default' => null,
                            'description' => 'The new values, as {"field":"value"}. Only the fields sent become '
                                . 'part of the proposal — anything omitted stays untouched, so this is a patch '
                                . 'and not a full replacement. Valid names per type are "writable_fields" in '
                                . '/describe. Required for create and update. '
                                . 'NOTE: a field cannot be CLEARED over this API. An empty string (and a string '
                                . 'of only whitespace) is dropped before the proposal is built, so a body '
                                . 'containing nothing but empty values answers 422 "must set at least one '
                                . 'field". Emptying a field currently needs the PHP API or an editor doing it '
                                . 'in the backend. '
                                . 'Three things that are not guessable from the names. "status" is 1 for '
                                . 'online/visible and 0 for offline; a create defaults to offline, so pass 1 if '
                                . 'the result is meant to be on the website. "template_id" exists on the '
                                . 'article type only — a category start article inherits its template, and a '
                                . 'template not permitted for the target category is refused rather than '
                                . 'silently swapped. And RENAMING A CATEGORY TAKES TWO PROPOSALS: type '
                                . '"category" writes "catname", the navigation entry, while the heading of the '
                                . 'page comes from the "name" of the category\'s start article, which is type '
                                . '"article" against the same id. Sending only the first leaves the two out of '
                                . 'step, and each proposal looks correct on its own — so do both, or expect a '
                                . 'category that is renamed in the menu and unchanged on the page.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => '',
                            'description' => 'Why this change should be made, in plain language. An editor '
                                . 'decides on the strength of this text plus the diff, so name what is wrong '
                                . 'with the current content rather than restating the new value: "heading was '
                                . 'identical to article 12, which hurts findability" works, "improved text" '
                                . 'does not.',
                        ],
                        'changeset' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'Optional grouping key, any short string. Proposals sharing one can '
                                . 'be approved by the editor in a single action and are applied in submission '
                                . 'order. It does NOT relax the dependency rule below — a changeset groups '
                                . 'decisions, it does not defer validation.',
                        ],
                        'proposals' => [
                            'type' => 'array',
                            'required' => false,
                            'default' => null,
                            'description' => 'Batch form: an array of objects each shaped like the single form '
                                . '(type, operation, target, fields, reason). Mutually exclusive with the '
                                . 'single-proposal keys. A "changeset" at the top level applies to every '
                                . 'element. The response reports each element separately by "index".',
                        ],
                    ],
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            'AI PLATFORM CHANGE REQUESTS — submit a proposal to create or update REDAXO content. NOTHING '
            . 'IS WRITTEN by this call. The proposal is queued for a human editor, who sees the target, a '
            . 'field-level diff and the "reason" text, and then approves or rejects it. If this token also '
            . 'holds the ai_platform/changes/approve scope it may approve its own proposal afterwards via '
            . 'POST /changes/approvals; otherwise the wait is for a person. '
            . 'Call GET /changes/describe first for the type names and field names valid on this site. '
            . 'One proposal per body, or many via "proposals". A batch answers 207 on partial success with '
            . 'a per-element result, so check each element — a refused element is not retried automatically. '
            . 'There is no limit on batch size. '
            . 'THE RULE THAT CATCHES CALLERS OUT: every reference is validated at submission, not at '
            . 'approval. A proposal pointing at a media file, link target, parent category, module or '
            . 'template that does not exist YET is refused immediately, even inside one batch in dependency '
            . 'order. Structure therefore has to be built in rounds: propose, approve, read the new id from '
            . '"created" in the approval response, then propose the next level against that id. '
            . 'A second proposal for the same target supersedes the earlier open one, so following up '
            . 'replaces rather than adds. '
            . 'Returns 201 with the new request id for a single proposal, 200 or 207 for a batch.',
            null,
            new BearerAuth(),
        );
    }

    private function registerDeletions(): void
    {
        RouteCollection::registerRoute(
            self::PREFIX . '/propose_delete',
            new Route(
                self::PREFIX . '/deletions',
                [
                    '_controller' => self::class . '::handleProposeDelete',
                    'Body' => [
                        'type' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'What kind of thing to delete: slice, article, category, media or '
                                . 'yform. Required in the single-proposal form. "operation" is not accepted '
                                . 'here — this route always means delete.',
                        ],
                        'target' => [
                            'type' => 'object',
                            'required' => false,
                            'default' => null,
                            'description' => 'Which existing thing to delete, e.g. '
                                . '{"article_id":12,"clang_id":1,"slice_id":345} or {"filename":"old.jpg"}. '
                                . 'Same target keys as the propose route, listed as "target_fields" by '
                                . '/describe. Required in the single-proposal form. No "fields" — a deletion '
                                . 'has no values.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => '',
                            'description' => 'Why this should be deleted. Weighs more here than on an update: '
                                . 'the editor cannot undo an approved deletion, so say what makes the content '
                                . 'obsolete.',
                        ],
                        'changeset' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'Optional grouping key, as on the propose route. Deletions in one '
                                . 'changeset can be decided together.',
                        ],
                        'proposals' => [
                            'type' => 'array',
                            'required' => false,
                            'default' => null,
                            'description' => 'Batch form: array of {type, target, reason} objects. Mutually '
                                . 'exclusive with the single form. Answers 207 on partial success.',
                        ],
                    ],
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            'AI PLATFORM CHANGE REQUESTS — propose deleting existing content. NOTHING IS DELETED by this '
            . 'call: the proposal goes into the same editorial queue as POST /changes and needs the same '
            . 'approval. '
            . 'This is a separate route with a separate scope (ai_platform/changes/propose_delete) so that '
            . 'a token can be permitted to propose changes without being permitted to propose deletions — '
            . 'the api addon authorises per route, never by inspecting the body. For the same reason '
            . 'operation:"delete" sent to POST /changes is refused with a pointer to here. '
            . 'Body shape is the propose route minus "operation" (always delete) and minus "fields" (a '
            . 'deletion has no values). Single body or "proposals" batch. '
            . 'Follow up with GET /changes/{id} to see whether an editor accepted it.',
            null,
            new BearerAuth(),
        );
    }

    private function registerApprovals(): void
    {
        RouteCollection::registerRoute(
            self::PREFIX . '/approve',
            new Route(
                self::PREFIX . '/approvals',
                [
                    '_controller' => self::class . '::handleApprove',
                    'Body' => [
                        'id' => [
                            'type' => 'integer',
                            'required' => false,
                            'default' => null,
                            'description' => 'Id of one own pending change request, as returned by '
                                . 'POST /changes. Single form; use "ids" for several. One of "id" or "ids" is '
                                . 'required.',
                        ],
                        'ids' => [
                            'type' => 'array',
                            'required' => false,
                            'default' => null,
                            'description' => 'Batch form: array of ids, approved in the given order. Mutually '
                                . 'exclusive with "id". Answers 207 when some succeed and some fail, so read '
                                . 'every element.',
                        ],
                        'note' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'Recorded next to the decision and shown in the editorial inbox. '
                                . 'Worth filling in: it is the only place explaining why this write did not '
                                . 'need a human.',
                        ],
                    ],
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            'AI PLATFORM CHANGE REQUESTS — approve own pending proposals without a human editor. THIS IS '
            . 'THE ONLY ENDPOINT HERE THAT WRITES TO THE WEBSITE; everything else only queues intentions. '
            . 'Holding the ai_platform/changes/approve scope IS the authorisation — there is no further '
            . 'setting to enable. '
            . 'Two limits always apply and cannot be switched off: only requests submitted by this same '
            . 'token can be approved (deciding someone else\'s proposal is not on offer), and there is no '
            . 'force — if the target changed since the proposal, was deleted, or a reference broke, the '
            . 'request stops and needs a person to look at it. A "force" key in the body is ignored. '
            . 'Every approval is stored with reviewed_via=api and flagged in the editorial inbox as '
            . 'decided without review, so this is visible rather than silent. '
            . 'THE RESPONSE IS WORTH READING, not just its status: per request it returns "created" with '
            . 'the ids the write produced (e.g. {"category_id":347} or {"article_id":350}) — that is the '
            . 'only way to learn the id of something just created, and what the next round of proposals '
            . 'points at. It also returns "values_after", the target\'s state after the write, which can '
            . 'legitimately differ from what was asked for: REDAXO renumbers sibling priorities gapless, '
            . 'so a requested priority 6 may end up as 3. '
            . 'Statuses: 200 all approved, 207 partly, 422 none.',
            null,
            new BearerAuth(),
        );
    }

    private function registerWithdrawals(): void
    {
        RouteCollection::registerRoute(
            self::PREFIX . '/withdraw',
            new Route(
                self::PREFIX . '/withdrawals',
                [
                    '_controller' => self::class . '::handleWithdraw',
                    'Body' => [
                        'id' => [
                            'type' => 'integer',
                            'required' => false,
                            'default' => null,
                            'description' => 'Id of one own change request that is still pending. One of "id" '
                                . 'or "ids" is required.',
                        ],
                        'ids' => [
                            'type' => 'array',
                            'required' => false,
                            'default' => null,
                            'description' => 'Batch form: array of ids. Mutually exclusive with "id". Answers '
                                . '207 when only some could be withdrawn, for example because one was already '
                                . 'approved.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'required' => false,
                            'default' => null,
                            'description' => 'Why it is being taken back. Stored on the row and visible to the '
                                . 'editor, which is the point: it turns a disappearing proposal into an '
                                . 'explained one.',
                        ],
                    ],
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            // POST rather than DELETE, and deliberately so: nothing is deleted. The
            // row stays and moves to status "withdrawn", which is what a caller
            // needs to be able to read afterwards. A DELETE would promise removal
            // and not deliver it.
            'AI PLATFORM CHANGE REQUESTS — take back own proposals that are still pending, for instance '
            . 'after noticing a mistake, so an editor does not have to spend a rejection on it. '
            . 'Nothing is deleted and no content is ever touched: the request moves to status "withdrawn", '
            . 'kept deliberately distinct from "rejected" because an editor saying no and a submitter '
            . 'taking something back are different facts, and only the first says anything about the '
            . 'proposal\'s quality. The row and its payload stay readable via GET /changes/{id}. '
            . 'Limits: only own requests, and only while still pending — once a request is approved the '
            . 'decision is no longer the submitter\'s, and by then it is usually already written. '
            . 'This is the one endpoint in the feature that cannot write, which is why it needs no policy. '
            . 'It is POST and not DELETE on purpose: DELETE would promise removal and not deliver it.',
            null,
            new BearerAuth(),
        );
    }

    // ---------------------------------------------------------------- handlers

    /**
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handleWithdraw($Parameter, array $Route = []): Response
    {
        if (null !== $off = self::refuseWhenDisabled()) {
            return $off;
        }

        $source = self::source($Route);
        if (null === $source) {
            return self::noToken();
        }

        $body = json_decode((string) rex::getRequest()->getContent(), true);
        if (!is_array($body)) {
            return self::error('Request body must be a JSON object.', 400);
        }

        $batch = $body['ids'] ?? null;
        if (null !== $batch && !is_array($batch)) {
            return self::error('"ids" must be an array.', 400);
        }

        $single = null === $batch;
        $ids = $single ? (isset($body['id']) ? [$body['id']] : []) : array_values($batch);

        if ([] === $ids) {
            return self::error('No change request id given. Use "id" or "ids".', 400);
        }

        // Reuses the propose batch limit rather than introducing a third one:
        // withdrawing writes nothing, so the only reason for a cap is keeping one
        // request bounded, and that number is already agreed for this endpoint.
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;
        $service = ChangeService::getInstance();

        $results = [];
        $ok = 0;
        foreach ($ids as $index => $raw) {
            $id = (int) $raw;
            $entry = ['index' => (int) $index, 'id' => $id];

            try {
                $service->withdraw($id, $source, $reason);
                $entry['ok'] = true;
                $entry['status'] = ChangeStatus::Withdrawn->value;
                ++$ok;
            } catch (Throwable $e) {
                $entry['ok'] = false;
                $entry['error'] = $e->getMessage();
            }

            $results[] = $entry;
        }

        if ($single) {
            $only = $results[0];
            unset($only['index']);

            return new JsonResponse(
                true === $only['ok'] ? ['data' => $only] : ['error' => $only['error'], 'id' => $only['id']],
                true === $only['ok'] ? 200 : 422,
            );
        }

        $status = match (true) {
            0 === $ok => 422,
            $ok === count($results) => 200,
            default => 207,
        };

        return new JsonResponse([
            'data' => $results,
            'meta' => ['withdrawn' => $ok, 'failed' => count($results) - $ok, 'total' => count($results)],
        ], $status);
    }


    /**
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handleApprove($Parameter, array $Route = []): Response
    {
        if (null !== $off = self::refuseWhenDisabled()) {
            return $off;
        }

        $source = self::source($Route);
        if (null === $source) {
            return self::noToken();
        }

        $body = json_decode((string) rex::getRequest()->getContent(), true);
        if (!is_array($body)) {
            return self::error('Request body must be a JSON object.', 400);
        }

        $batch = $body['ids'] ?? null;
        if (null !== $batch && !is_array($batch)) {
            return self::error('"ids" must be an array.', 400);
        }

        $single = null === $batch;
        $ids = $single
            ? (isset($body['id']) ? [$body['id']] : [])
            : array_values($batch);

        if ([] === $ids) {
            return self::error('No change request id given. Use "id" or "ids".', 400);
        }

        $note = is_string($body['note'] ?? null) ? $body['note'] : null;
        $service = ChangeService::getInstance();

        $results = [];
        $ok = 0;
        foreach ($ids as $index => $raw) {
            $id = (int) $raw;
            $entry = ['index' => (int) $index, 'id' => $id];

            try {
                $result = $service->approveViaApi($id, $source, $note);
                $entry['ok'] = $result->success;
                if ($result->success) {
                    ++$ok;
                    // The ids the write produced are the point of this endpoint:
                    // they are what the next round of proposals can point at.
                    $entry['created'] = $result->createdIds ?? null;
                    $entry['values_after'] = $result->valuesAfter ?? null;
                } else {
                    $entry['error'] = $result->error ?? 'unknown error';
                }
            } catch (Throwable $e) {
                $entry['ok'] = false;
                $entry['error'] = $e->getMessage();
            }

            $results[] = $entry;
        }

        // Cache rebuilds are collected during the writes and flushed once, not
        // per request — the same batching the backend does.
        $service->flushCacheRebuilds();

        if ($single) {
            $only = $results[0];
            unset($only['index']);

            return new JsonResponse(
                true === $only['ok'] ? ['data' => $only] : ['error' => $only['error'], 'id' => $only['id']],
                true === $only['ok'] ? 200 : 422,
            );
        }

        $status = match (true) {
            0 === $ok => 422,
            $ok === count($results) => 200,
            default => 207,
        };

        return new JsonResponse([
            'data' => $results,
            'meta' => ['approved' => $ok, 'failed' => count($results) - $ok, 'total' => count($results)],
        ], $status);
    }


    /**
     * Two modes on one route, chosen by whether a type was named.
     *
     * Without `type`: the catalogue — which types exist here, which operations
     * each supports, which target and payload fields they accept. This is what a
     * caller reads once, before its first proposal, because metainfo fields and
     * the YForm allow list differ per installation.
     *
     * With `type` and `target`: the current state of that one target.
     *
     * They used to be `/types` and `/current`, two routes and therefore two
     * scopes for what is one privilege: looking at what is there, changing
     * nothing. Both answer "describe", one in general and one for a specific
     * thing, so the presence of `type` is a natural switch rather than an
     * overload.
     *
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handleDescribe($Parameter, array $Route = []): Response
    {
        if (null !== $off = self::refuseWhenDisabled()) {
            return $off;
        }

        $query = rex::getRequest()->query->all();
        $type = is_string($query['type'] ?? null) ? trim($query['type']) : '';

        if ('' === $type) {
            // A `target` without a `type` is a caller that meant to ask about
            // one thing and forgot to say which kind. Answering with the
            // catalogue would look like success.
            if (isset($query['target']) && '' !== (string) $query['target']) {
                return self::error('The "type" parameter is required when "target" is given.', 400);
            }

            return self::describeCatalogue();
        }

        $handler = HandlerRegistry::get($type);
        if (null === $handler) {
            return self::unknownType($type);
        }

        $rawTarget = $query['target'] ?? '';

        try {
            $target = self::decodeObject(is_string($rawTarget) ? $rawTarget : '', 'target');
            // An Update target is the right shape for reading: it addresses
            // something that is supposed to exist already.
            $built = ChangePayloadBuilder::build($handler, ChangeOperation::Update, $target, []);
            $state = ChangeService::getInstance()->read($built['target']);
        } catch (Throwable $e) {
            return self::fail($e);
        }

        if (!$state->exists()) {
            return self::error($state->description . ' does not exist.', 404);
        }

        return new JsonResponse([
            'data' => [
                'type' => $type,
                'target' => $state->description,
                'values' => $state->values,
            ],
        ]);
    }

    /**
     * Stages one file and reserves its media pool name.
     *
     * Two body shapes, because the callers differ: a normal HTTP client sends
     * `multipart/form-data` with a `file` part, an agent that only knows how to
     * PUT bytes sends the raw body plus `?filename=`. Both end in the same place.
     *
     * **The name is reserved here, not at approval.** That is the point of the
     * route existing separately from `/propose`: the caller needs the final
     * filename *before* it can write a slice proposal referencing the image, and
     * `rex_mediapool::filename()` would otherwise subindex a collision at
     * approval time into a name nobody could have predicted.
     *
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handleUpload($Parameter, array $Route = []): Response
    {
        if (null !== $off = self::refuseWhenDisabled()) {
            return $off;
        }

        $source = self::source($Route);
        if (null === $source) {
            return self::noToken();
        }

        $request = rex::getRequest();
        $file = $request->files->get('file');
        $temporary = null;

        if (null !== $file) {
            if (!$file->isValid()) {
                return self::error($file->getErrorMessage(), 400);
            }
            $path = $file->getPathname();
            $name = (string) $file->getClientOriginalName();
        } else {
            // Raw body. The name cannot be guessed from bytes, so it is required
            // rather than invented — a media file called "upload.bin" is not a
            // usable default, it is a name somebody has to fix later.
            $name = (string) ($request->query->get('filename') ?? '');
            if ('' === trim($name)) {
                return self::error('Send multipart/form-data with a "file" part, or a raw body plus a "filename" query parameter.', 400);
            }

            $body = (string) $request->getContent();
            if ('' === $body) {
                return self::error('The request body is empty.', 400);
            }

            $temporary = rex_path::addonData('ai_platform', 'pending/incoming_' . bin2hex(random_bytes(8)));
            rex_dir::create(dirname($temporary));
            if (false === rex_file::put($temporary, $body)) {
                return self::error('The upload could not be staged.', 500);
            }
            $path = $temporary;
        }

        try {
            $upload = (new PendingUploadStore())->stage($path, $name, $source, true);
        } catch (ValidationException $e) {
            if (null !== $temporary) {
                rex_file::delete($temporary);
            }

            return self::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            if (null !== $temporary) {
                rex_file::delete($temporary);
            }

            return self::fail($e);
        }

        return new JsonResponse([
            'data' => $upload->toArray(),
            'meta' => [
                'staged' => true,
                'in_media_pool' => false,
                // Spelled out because the sequence is the part a caller gets
                // wrong: the handle is useless on its own.
                'next' => 'POST ' . self::PREFIX . ' with {"type":"media","operation":"create",'
                    . '"target":{"category_id":<id>,"filename":"' . $upload->filename . '"},'
                    . '"fields":{"upload":"' . $upload->handle . '","title":"…"}}',
                'reservation_hours' => PendingUploadStore::RESERVATION_HOURS,
            ],
        ], 201);
    }

    private static function describeCatalogue(): Response
    {
        try {
            $types = [];
            foreach (ChangePayloadBuilder::describe() as $type => $info) {
                $handler = HandlerRegistry::get($type);
                if (null === $handler) {
                    continue;
                }

                $types[$type] = [
                    'label' => $handler->getLabel(),
                    'operations' => array_map(
                        static fn(ChangeOperation $op): string => $op->value,
                        $handler->supportedOperations(),
                    ),
                    'target_fields' => $info['target'],
                    'writable_fields' => $info['fields'],
                ];

                // Only the slice type carries this. It is the difference
                // between knowing that value1..value20 exist and knowing which
                // of them the module in front of you reads.
                if (isset($info['modules'])) {
                    $types[$type]['modules'] = $info['modules'];
                }
            }
        } catch (Throwable $e) {
            return self::fail($e);
        }

        return new JsonResponse([
            'data' => $types,
            'meta' => [
                // No cap. What a batch can do is decided by the writes it
                // triggers, not by how many are in it, and stopping at an
                // arbitrary number only meant callers had to split for no
                // benefit.
                'batch_limit' => null,
                // So a caller can read the approval rules instead of discovering
                // them one refusal at a time.
                'api_approval' => ApiApproval::describe(),
            ],
        ]);
    }

    /**
     * Own change requests: a list, or one of them.
     *
     * `list` and `get` used to be separate routes and therefore separate scopes,
     * for one and the same privilege — both are read-only and both filter on the
     * calling token's `source_key`, so there was never a reason to grant one and
     * refuse the other. The id segment is optional (its `null` default is what
     * makes it so) and decides which shape comes back.
     *
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handleRequests($Parameter, array $Route = []): Response
    {
        if (null !== $off = self::refuseWhenDisabled()) {
            return $off;
        }

        $source = self::source($Route);
        if (null === $source) {
            return self::noToken();
        }

        $id = (int) ($Parameter['id'] ?? 0);
        if ($id > 0) {
            return self::describeOneRequest($id, $source);
        }

        $query = rex::getRequest()->query->all();
        $status = is_string($query['status'] ?? null) ? $query['status'] : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 50)));

        if (null !== $status && null === ChangeStatus::tryFrom($status)) {
            return self::error(sprintf(
                'Unknown status "%s". Use one of: %s',
                $status,
                implode(', ', array_map(static fn(ChangeStatus $s): string => $s->value, ChangeStatus::cases())),
            ), 400);
        }

        // Scoped to the calling token on purpose: one token has no business
        // reading what another one proposed.
        $where = 'source_key = :key';
        $params = [':key' => $source->key];
        if (null !== $status) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $table = rex::getTable('ai_change_request');
        $sql = rex_sql::factory();

        try {
            $total = (int) ($sql->getArray(
                'SELECT COUNT(*) AS n FROM ' . $table . ' WHERE ' . $where,
                $params,
            )[0]['n'] ?? 0);

            $rows = $sql->getArray(
                'SELECT id FROM ' . $table . ' WHERE ' . $where
                . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
                $params,
            );

            $store = new ChangeRequestStore();
            $data = [];
            foreach ($rows as $row) {
                $request = $store->find((int) $row['id']);
                if (null !== $request) {
                    $data[] = self::summarise($request);
                }
            }

            $open = (int) ($sql->getArray(
                'SELECT COUNT(*) AS n FROM ' . $table . ' WHERE source_key = :key AND status = :status',
                [':key' => $source->key, ':status' => ChangeStatus::Pending->value],
            )[0]['n'] ?? 0);
        } catch (Throwable $e) {
            return self::fail($e);
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
                'source_key' => $source->key,
                // `open` is a fact: how many of this token's requests are still
                // waiting for a decision. It used to sit in a `quota` object next
                // to `max_open` and `remaining`, read from
                // `changes_max_open_per_source` — a setting that was removed
                // because nothing is written without a decision anyway. The
                // response kept announcing the limit afterwards, so a
                // well-behaved agent could stop proposing at a ceiling that no
                // longer existed. A limit that is not enforced must not be
                // announced.
                'open' => $open,
            ],
        ]);
    }

    private static function describeOneRequest(int $id, Source $source): Response
    {
        try {
            $request = (new ChangeRequestStore())->find($id);
        } catch (Throwable $e) {
            return self::fail($e);
        }

        // Same 404 for "does not exist" and "belongs to another token": whether
        // an id exists is not this token's business either.
        if (null === $request || $request->source->key !== $source->key) {
            return self::error('Change request not found.', 404);
        }

        $data = self::summarise($request);
        $data['payload'] = $request->effectivePayload()->toArray();
        $data['snapshot_before'] = $request->snapshotBefore;
        $data['apply_result'] = $request->applyResult;
        $data['apply_error'] = $request->applyError;

        // The situation report only means something while a decision is still
        // pending; afterwards the outcome is what counts.
        if ($request->isOpen() && HandlerRegistry::has($request->type)) {
            try {
                $inspection = ChangeService::getInstance()->inspectTarget($request);
                $data['situation'] = [
                    'target_gone' => $inspection->gone,
                    'broken_references' => $inspection->brokenReferences,
                    'target_changed' => $inspection->changed,
                    'changed_fields' => $inspection->changedFields,
                    'context_changed' => $inspection->contextChanged,
                    'blocked' => $inspection->gone
                        || [] !== $inspection->brokenReferences
                        || ($inspection->changed && ChangeService::getInstance()->blocksOnStale()),
                ];
            } catch (Throwable) {
                // A failing inspection must not hide the request itself.
                $data['situation'] = null;
            }
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handlePropose($Parameter, array $Route = []): Response
    {
        return self::submit($Route, false);
    }

    /**
     * @param array<string, mixed> $Parameter
     * @param array<string, mixed> $Route
     */
    public static function handleProposeDelete($Parameter, array $Route = []): Response
    {
        return self::submit($Route, true);
    }

    // ----------------------------------------------------------------- submit

    /**
     * Shared body for both POST routes.
     *
     * @param array<string, mixed> $Route
     */
    private static function submit(array $Route, bool $deletion): Response
    {
        if (null !== $off = self::refuseWhenDisabled()) {
            return $off;
        }

        $source = self::source($Route);
        if (null === $source) {
            return self::noToken();
        }

        $body = json_decode((string) rex::getRequest()->getContent(), true);
        if (!is_array($body)) {
            return self::error('Request body must be a JSON object.', 400);
        }

        $batch = $body['proposals'] ?? null;
        if (null !== $batch && !is_array($batch)) {
            return self::error('"proposals" must be an array.', 400);
        }

        $single = null === $batch;
        $items = $single ? [$body] : array_values($batch);

        if ([] === $items) {
            return self::error('No proposals in the request.', 400);
        }

        // A changeset named at the top level applies to every element that does
        // not name its own. That is the whole point of a batch: one decision.
        $defaultChangeset = is_string($body['changeset'] ?? null) && '' !== $body['changeset']
            ? $body['changeset']
            : null;

        $results = [];
        $ok = 0;
        foreach ($items as $index => $item) {
            $result = is_array($item)
                ? self::proposeOne($item, $source, $deletion, $defaultChangeset)
                : ['ok' => false, 'error' => 'Proposal must be an object.'];

            $result['index'] = (int) $index;
            $results[] = $result;
            if (true === $result['ok']) {
                ++$ok;
            }
        }

        if ($single) {
            $only = $results[0];
            unset($only['index']);

            return new JsonResponse(
                true === $only['ok'] ? ['data' => $only] : ['error' => $only['error']],
                true === $only['ok'] ? 201 : 422,
            );
        }

        // 207 for a mixed batch, so a client cannot read a 200 as "all fine".
        $status = match (true) {
            0 === $ok => 422,
            $ok === count($results) => 201,
            default => 207,
        };

        return new JsonResponse([
            'data' => $results,
            'meta' => [
                'submitted' => $ok,
                'failed' => count($results) - $ok,
                'total' => count($results),
            ],
        ], $status);
    }

    /**
     * One proposal. Errors are returned rather than thrown, so a batch keeps
     * going and the caller learns which element failed and why.
     *
     * @param array<string, mixed> $item
     * @return array{ok: bool, id?: int, target?: string, type?: string, operation?: string, error?: string}
     */
    private static function proposeOne(array $item, Source $source, bool $deletion, ?string $defaultChangeset): array
    {
        $type = is_string($item['type'] ?? null) ? $item['type'] : '';
        if ('' === $type) {
            return ['ok' => false, 'error' => '"type" is required.'];
        }

        $handler = HandlerRegistry::get($type);
        if (null === $handler) {
            return ['ok' => false, 'error' => sprintf(
                'Unknown change type "%s". Available: %s',
                $type,
                implode(', ', array_keys(HandlerRegistry::all())),
            )];
        }

        if ($deletion) {
            $operation = ChangeOperation::Delete;
        } else {
            $raw = is_string($item['operation'] ?? null) ? $item['operation'] : 'update';
            $operation = ChangeOperation::tryFrom($raw);
            if (null === $operation) {
                return ['ok' => false, 'error' => sprintf('Unknown operation "%s". Use create or update.', $raw)];
            }
            if (ChangeOperation::Delete === $operation) {
                return ['ok' => false, 'error' => 'Deletions go to POST ' . self::PREFIX
                    . '/deletions, which carries its own scope.'];
            }
        }

        $target = $item['target'] ?? [];
        $fields = $item['fields'] ?? [];
        if (!is_array($target) || !is_array($fields)) {
            return ['ok' => false, 'error' => '"target" and "fields" must be objects.'];
        }

        $reason = is_string($item['reason'] ?? null) ? $item['reason'] : '';
        $changeset = is_string($item['changeset'] ?? null) && '' !== $item['changeset']
            ? $item['changeset']
            : $defaultChangeset;

        try {
            $built = ChangePayloadBuilder::build($handler, $operation, $target, $fields);
            $service = ChangeService::getInstance();

            $id = ChangeOperation::Delete === $operation
                ? $service->proposeDelete($built['target'], $reason, $source, $changeset)
                : $service->propose($built['target'], $built['payload'], $reason, $source, $changeset);

            return [
                'ok' => true,
                'id' => $id,
                'type' => $type,
                'operation' => $operation->value,
                'target' => $built['target']->describe(),
                'status' => ChangeStatus::Pending->value,
                'applied' => false,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The calling token's identity as a Source.
     *
     * Derived from `$Route['authorization']`, which is the Auth object the api
     * addon already validated for this request — never from the body.
     *
     * @param array<string, mixed> $Route
     */
    private static function source(array $Route): ?Source
    {
        $auth = $Route['authorization'] ?? null;
        if (!$auth instanceof BearerAuth) {
            return null;
        }

        $token = $auth->getAuthorizationObject();
        if (!$token instanceof Token || null === $token->getId()) {
            return null;
        }

        return Source::apiToken($token->getId(), $token->getName());
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarise(ChangeRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type,
            'operation' => $request->operation->value,
            'target' => $request->targetLabel !== '' ? $request->targetLabel : $request->target->describe(),
            'status' => $request->status->value,
            'reason' => $request->reason,
            'changeset' => $request->changesetId,
            'review_note' => $request->reviewNote,
            // Which channel decided it. Without this in the payload the field
            // exists only in the database, and a caller cannot tell its own
            // unattended approval from an editor's decision.
            'reviewed_via' => $request->reviewedVia,
            'reviewed_at' => $request->reviewedAt?->format(DATE_ATOM),
            'applied_at' => $request->appliedAt?->format(DATE_ATOM),
            'created_at' => $request->createdAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private static function decodeObject(string $raw, string $what): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException(sprintf('"%s" must be a JSON object.', $what));
        }

        return $decoded;
    }

    /**
     * The master switch also closes this door.
     *
     * `boot.php` does not register the routes at all while the feature is off,
     * so this is the second lock: the config could in principle be flipped
     * between boot and dispatch, and a 503 explains more than the 500 that
     * `ChangeService::assertEnabled()` would produce.
     */
    private static function refuseWhenDisabled(): ?Response
    {
        if (ChangeService::getInstance()->isEnabled()) {
            return null;
        }

        return self::error('Change requests are switched off in this installation.', 503);
    }

    private static function noToken(): Response
    {
        return self::error('Could not identify the calling token.', 401);
    }

    private static function unknownType(string $type): Response
    {
        return self::error(sprintf(
            'Unknown change type "%s". Available: %s',
            $type,
            implode(', ', array_keys(HandlerRegistry::all())),
        ), 400);
    }

    private static function error(string $message, int $status): Response
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private static function fail(Throwable $e): Response
    {
        return new JsonResponse(['error' => $e->getMessage()], 400);
    }
}
