<?php

namespace App\Support;

/**
 * Hides most of a client's name while leaving it recognisable.
 *
 * "Kyle Padilla" becomes "Kyle ******a". An agent can still tell which sale a
 * row is, and the commission slip stops being a list of the company's clients
 * that can be forwarded, printed or left on a desk.
 *
 * The first name stays because it identifies the row for the person who made
 * the sale; the surname is what would let a stranger find the client. A name
 * of one word is masked outright, since that word is usually the surname.
 */
class MaskedName
{
    public static function of(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $name) ?: [];

        if (count($words) === 1) {
            return self::maskWord($words[0]);
        }

        $first = array_shift($words);

        return $first . ' ' . implode(' ', array_map(self::maskWord(...), $words));
    }

    /**
     * Stars every letter and digit but the last, so the length still shows and
     * punctuation — a hyphen in a double-barrelled name, a full stop after an
     * initial — survives.
     */
    protected static function maskWord(string $word): string
    {
        $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = null;

        foreach ($characters as $index => $character) {
            if (preg_match('/[\p{L}\p{N}]/u', $character)) {
                $last = $index;
            }
        }

        foreach ($characters as $index => $character) {
            if ($index !== $last && preg_match('/[\p{L}\p{N}]/u', $character)) {
                $characters[$index] = '*';
            }
        }

        return implode('', $characters);
    }
}
