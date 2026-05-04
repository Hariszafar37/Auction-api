<?php

namespace App\Http\Controllers\Api\V1\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EmailLogsController extends Controller
{
    /** Return at most this many emails, newest first. */
    private const MAX_EMAILS = 50;

    /**
     * GET /api/v1/dev/email-logs
     *
     * Reads laravel.log, extracts every email entry logged by the `log` mail
     * driver, and returns them as structured JSON.  Blocked in production.
     */
    public function index(): JsonResponse
    {
        if (app()->environment('production')) {
            return response()->json(['error' => 'Not available in production.'], 403);
        }

        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath) || ! is_readable($logPath)) {
            return response()->json(['emails' => [], 'total' => 0]);
        }

        $content = file_get_contents($logPath);
        $emails  = $this->parseEmails($content);

        return response()->json([
            'emails' => array_values($emails),
            'total'  => count($emails),
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Split the raw log content into per-email blocks and parse each one.
     * Returns up to MAX_EMAILS entries, newest first.
     */
    private function parseEmails(string $content): array
    {
        // Each email entry the `log` mail driver writes starts with the Laravel
        // log timestamp prefix immediately followed by "From:".
        // e.g.  [2026-04-16 13:01:14] local.DEBUG: From: App Name <hello@example.com>
        $blocks = preg_split(
            '/(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \w+\.\w+: From:)/',
            $content,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $emails = [];
        foreach ($blocks as $block) {
            $parsed = $this->parseBlock($block);
            if ($parsed !== null) {
                $emails[] = $parsed;
            }
        }

        // Reverse so newest entry is first, then cap.
        return array_slice(array_reverse($emails), 0, self::MAX_EMAILS);
    }

    /**
     * Parse a single multi-line email block extracted from the log file.
     * Returns null if the block does not look like a valid email entry.
     */
    private function parseBlock(string $block): ?array
    {
        // ── Timestamp ────────────────────────────────────────────────────────
        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $block, $tsM)) {
            return null;
        }
        $timestamp = $tsM[1];

        // ── Headers ──────────────────────────────────────────────────────────
        preg_match('/^\[.+?\] \w+\.\w+: From: (.+?)[\r\n]/m', $block, $fromM);
        preg_match('/^To: (.+?)[\r\n]/m',                     $block, $toM);
        preg_match('/^Subject: (.+?)[\r\n]/m',                $block, $subM);

        $from    = trim($fromM[1]  ?? '');
        $to      = trim($toM[1]   ?? '');
        $subject = trim($subM[1]  ?? '');

        if (! $to || ! $subject) {
            return null;
        }

        // ── MIME boundary ────────────────────────────────────────────────────
        preg_match('/boundary=(\S+)/i', $block, $boundM);
        $boundary = $boundM[1] ?? null;

        $plainText   = '';
        $htmlBody    = '';
        $actionLinks = [];

        if ($boundary) {
            $bq = preg_quote($boundary, '/');

            // text/plain section — used for action-link extraction
            $plainPat = '/--' . $bq . '\r?\nContent-Type: text\/plain[^\n]*\r?\n'
                      . '(?:Content-Transfer-Encoding:[^\n]*\r?\n)?\r?\n'
                      . '(.*?)(?=--' . $bq . ')/s';
            if (preg_match($plainPat, $block, $pm)) {
                $plainText = trim($pm[1]);
            }

            // text/html section — sent to the frontend for rendered preview
            $htmlPat = '/--' . $bq . '\r?\nContent-Type: text\/html[^\n]*\r?\n'
                     . '(?:Content-Transfer-Encoding:[^\n]*\r?\n)?\r?\n'
                     . '(.*?)(?=--' . $bq . '--)/s';
            if (preg_match($htmlPat, $block, $hm)) {
                $htmlBody = trim($hm[1]);
            }
        }

        // ── Action links ─────────────────────────────────────────────────────
        // Extract "Label: https://..." lines from the plain-text part.
        // Skips the app-branding line ("Car Auction API: http://host") and any
        // bare-hostname links that have no meaningful path component.
        if (preg_match_all('/^([A-Z][^\n:]{2,60}):\s*(https?:\/\/\S+)/m', $plainText, $lms, PREG_SET_ORDER)) {
            foreach ($lms as $lm) {
                $label   = trim($lm[1]);
                $url     = trim($lm[2]);
                $urlPath = parse_url($url, PHP_URL_PATH) ?? '';

                if ($label === 'Car Auction API') {
                    continue;
                }
                if (empty($urlPath) || $urlPath === '/') {
                    continue;
                }

                $actionLinks[] = ['label' => $label, 'url' => $url];
            }
        }

        // Fix quoted-printable encoding artefacts that the log driver leaves behind.
        $plainText = str_replace(['Â©', '=\r\n', '=\n'], ['©', '', ''], $plainText);

        return [
            'id'           => md5($timestamp . $to . $subject),
            'timestamp'    => $timestamp,
            'from'         => $from,
            'to'           => $to,
            'subject'      => $subject,
            'plain_text'   => $plainText,
            'html_body'    => $htmlBody,
            'action_links' => $actionLinks,
        ];
    }
}
