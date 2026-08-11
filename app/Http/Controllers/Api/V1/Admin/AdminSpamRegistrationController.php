<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SpamRegistrationScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin console for clearing the accounts left behind by the registration
 * list-bombing campaign.
 *
 * Scope is deliberately narrow. This is NOT a generic command runner — an
 * endpoint that executes arbitrary artisan commands over HTTP is remote code
 * execution, and one stolen admin token would own the server. The only action
 * reachable here is "delete spam registrations the server itself has verified
 * are safe to delete".
 *
 * The client sends a list of ids to remove, but that list is only ever a
 * REQUEST. SpamRegistrationScanner::purge() re-derives eligibility for every id
 * from the live database — boundary, activity in any table with a foreign key
 * to users, privileged roles — and refuses anything that fails. A tampered
 * request asking to delete id 1 gets the same answer as one asking politely.
 */
class AdminSpamRegistrationController extends Controller
{
    public function __construct(private readonly SpamRegistrationScanner $scanner)
    {
    }

    /**
     * GET /api/v1/admin/spam-registrations
     *
     * Everything above the boundary, each row carrying whether it can be
     * deleted and — when it cannot — exactly what is holding it.
     */
    public function index(Request $request): JsonResponse
    {
        $afterId = $this->boundary($request);
        $rows    = $this->scanner->scan($afterId);

        $deletable = array_values(array_filter($rows, fn (array $r) => $r['deletable']));
        $blocked   = array_values(array_filter($rows, fn (array $r) => ! $r['deletable']));

        return $this->success([
            'boundary'       => $afterId,
            'candidates'     => $rows,
            'summary'        => [
                'total'           => count($rows),
                'deletable'       => count($deletable),
                'blocked'         => count($blocked),
                'looks_automated' => count(array_filter($rows, fn (array $r) => $r['looks_automated'])),
            ],
            'activity_tables' => array_keys($this->scanner->activityTables()),
        ]);
    }

    /**
     * POST /api/v1/admin/spam-registrations/purge
     *
     * Deletes the requested ids, after independently confirming each one.
     */
    public function purge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'min:1'],

            // Typed-out confirmation, mirroring the CLI prompt. Cheap to send
            // deliberately and impossible to send by accident — which is the
            // point, given the action cannot be undone.
            'confirm'    => ['required', 'string', 'in:DELETE'],
        ]);

        $result = $this->scanner->purge(
            $validated['user_ids'],
            $this->boundary($request),
            $request->user()?->id,
        );

        return $this->success(
            $result,
            sprintf(
                'Deleted %d account(s). %d skipped.',
                count($result['deleted']),
                count($result['skipped']),
            ),
        );
    }

    /**
     * The boundary is adjustable but can never be lowered past the default —
     * the operator confirmed everything up to id 40 is genuine, and no request
     * parameter should be able to put those accounts back in scope.
     */
    private function boundary(Request $request): int
    {
        return max(
            SpamRegistrationScanner::DEFAULT_BOUNDARY,
            $request->integer('after_id', SpamRegistrationScanner::DEFAULT_BOUNDARY),
        );
    }
}
