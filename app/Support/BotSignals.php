<?php

namespace App\Support;

/**
 * Heuristics that separate a human-filled registration from a scripted one.
 *
 * Written in response to a live list-bombing campaign against POST /auth/register:
 * a bot was feeding the endpoint harvested third-party email addresses so that our
 * SES-verified sender mailed a verification link to people who never signed up.
 * The junk rows were the symptom; the cost was our sender reputation.
 *
 * Every one of those submissions shared one tell — the name fields were random
 * strings ("hKhPmploaIgTZdDuvcXul", "rbXOYBmDXngiwKbATIckP"), while the email
 * addresses were real and therefore indistinguishable from a genuine signup.
 * So the name is the highest-signal field we have, and it costs a real user nothing.
 *
 * The scoring below is deliberately biased towards letting a human through: every
 * ambiguous case resolves in favour of "human". False negatives just mean one more
 * junk row; a false positive turns away a paying bidder.
 */
final class BotSignals
{
    /**
     * Score at or above which a name is treated as machine-generated.
     *
     * Calibrated against the live attack payloads (which score 7-12) and against
     * awkward but real names — "McDonald-O'Brien", "Mary Jane Elizabeth",
     * "Schwarzenegger", "Krzysztof", "JEAN-PIERRE" — which all score 0-4.
     */
    private const REJECT_AT = 5;

    /**
     * Names shorter than this are never scored. Protects "Ng", "Xu", "Li", "Oz" —
     * short real names trip the vowel-ratio test for obvious reasons.
     */
    private const MIN_SCORABLE_LENGTH = 8;

    public static function looksMachineGenerated(string $name): bool
    {
        return self::nameScore($name) >= self::REJECT_AT;
    }

    /**
     * Higher means less human. See the individual signals for the reasoning.
     */
    public static function nameScore(string $name): int
    {
        $letters = self::letters($name);
        $length  = count($letters);

        if ($length < self::MIN_SCORABLE_LENGTH) {
            return 0;
        }

        $score = 0;

        // ── Signal 1: interior capitals (the strongest discriminator) ────────────
        //
        // A capital letter in the middle of a token is rare in real names — you get
        // one for "McDonald", "MacArthur", "DeAngelo" and essentially never more
        // than two. The bot averages seven. Tokens that are entirely uppercase are
        // excluded, otherwise anyone typing "JEAN-PIERRE" would score maximally.
        $interiorCaps = self::interiorCapitals($name);

        if ($interiorCaps >= 3) {
            $score += 3;
        }
        if ($interiorCaps >= 5) {
            $score += 2;
        }
        if ($interiorCaps >= 7) {
            $score += 1;
        }

        // ── Signal 2: length ────────────────────────────────────────────────────
        // Real single name parts run long occasionally ("Christopherson" is 14),
        // so length alone is never enough to reject — it only compounds.
        if ($length >= 14) {
            $score += 1;
        }
        if ($length >= 18) {
            $score += 1;
        }

        // ── Signal 3: vowel ratio ───────────────────────────────────────────────
        // Pronounceable names sit around 30-45% vowels. Random alphabet draws sit
        // near 20%. 'y' counts as a vowel, and non-ASCII letters count as vowels,
        // so accented and non-Latin names can never be penalised here.
        $vowels     = self::countVowels($letters);
        $vowelRatio = $vowels / $length;

        if ($vowelRatio < 0.25) {
            $score += 2;
        }
        if ($vowels === 0) {
            $score += 1;
        }

        // ── Signal 4: longest consonant run ─────────────────────────────────────
        // Slavic surnames reach four ("Schwarz" → "chw", "Krzysztof" → "Krz").
        // Five or more in a row is where real orthography stops and rand() starts.
        if (self::longestConsonantRun($letters) >= 5) {
            $score += 2;
        }

        return $score;
    }

    /**
     * Uppercase letters that appear inside a token rather than starting one.
     *
     * Tokens are split on whitespace, hyphens and apostrophes so that "Mary Jane",
     * "Jean-Pierre" and "O'Brien" contribute nothing. Fully-uppercase tokens are
     * skipped entirely — shouting is a human habit, not a bot one.
     */
    private static function interiorCapitals(string $name): int
    {
        $tokens = preg_split("/[\s\-'’_.]+/u", $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count  = 0;

        foreach ($tokens as $token) {
            $letters = self::letters($token);

            if (count($letters) < 2) {
                continue;
            }

            // An all-caps token has no "interior" capitals worth counting.
            if (! self::hasLowercase($letters)) {
                continue;
            }

            foreach (array_slice($letters, 1) as $letter) {
                if (self::isUppercase($letter)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return list<string> the letter characters of $value, in order
     */
    private static function letters(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $chars,
            static fn (string $char): bool => preg_match('/\p{L}/u', $char) === 1,
        ));
    }

    /**
     * @param list<string> $letters
     */
    private static function countVowels(array $letters): int
    {
        return count(array_filter($letters, self::isVowel(...)));
    }

    /**
     * @param list<string> $letters
     */
    private static function longestConsonantRun(array $letters): int
    {
        $longest = 0;
        $current = 0;

        foreach ($letters as $letter) {
            if (self::isVowel($letter)) {
                $current = 0;
                continue;
            }

            $current++;
            $longest = max($longest, $current);
        }

        return $longest;
    }

    private static function isVowel(string $char): bool
    {
        $lower = mb_strtolower($char, 'UTF-8');

        // Anything outside a-z (accented Latin, Cyrillic, CJK, …) counts as a vowel
        // so that non-English names never accumulate consonant-run or ratio points.
        if (preg_match('/^[a-z]$/', $lower) !== 1) {
            return true;
        }

        return str_contains('aeiouy', $lower);
    }

    private static function isUppercase(string $char): bool
    {
        // Caseless scripts report equal for both conversions — treat them as lowercase
        // so a CJK name registers no interior capitals.
        return mb_strtoupper($char, 'UTF-8') === $char
            && mb_strtolower($char, 'UTF-8') !== $char;
    }

    /**
     * @param list<string> $letters
     */
    private static function hasLowercase(array $letters): bool
    {
        foreach ($letters as $letter) {
            if (mb_strtolower($letter, 'UTF-8') === $letter
                && mb_strtoupper($letter, 'UTF-8') !== $letter) {
                return true;
            }
        }

        return false;
    }
}
