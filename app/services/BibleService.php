<?php
/**
 * CoderAI Bible Service
 * Looks up Bible verses from local JSON files
 * Only used for CHURCH workspace
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class BibleService
{
    private static $bibles = [];
    private static $biblesPath;

    /**
     * Search for relevant verses based on user message
     * Returns context to add to system prompt
     */
    public static function getContextForMessage($message, $language = 'af')
    {
        // Detect language from message
        $isAfrikaans = self::isAfrikaans($message);
        $bibleFile = $isAfrikaans ? 'af_1933_53.json' : 'en_kjv1611.json';

        $bible = self::loadBible($bibleFile);
        if (!$bible) {
            return '';
        }

        // Extract book/chapter/verse references from message
        $references = self::extractReferences($message);

        $verses = [];
        foreach ($references as $ref) {
            $verse = self::lookupVerse($bible, $ref);
            if ($verse) {
                $verses[] = $verse;
            }
        }

        // Also search for keywords if no direct references found
        if (empty($verses)) {
            $keywords = self::extractKeywords($message);
            $verses = self::searchByKeywords($bible, $keywords, 3);
        }

        if (empty($verses)) {
            return '';
        }

        // Format as context
        $context = "BIBLE_CONTEXT:\n";
        foreach ($verses as $verse) {
            $context .= "- {$verse['reference']}: {$verse['text']}\n";
        }

        return $context;
    }

    /**
     * Load Bible JSON file
     */
    private static function loadBible($filename)
    {
        if (isset(self::$bibles[$filename])) {
            return self::$bibles[$filename];
        }

        $path = self::getBiblesPath() . '/' . $filename;

        if (!file_exists($path)) {
            error_log("[BibleService] Bible file not found: {$path}");
            return null;
        }

        $content = file_get_contents($path);
        $bible = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[BibleService] JSON error loading {$filename}: " . json_last_error_msg());
            return null;
        }

        self::$bibles[$filename] = $bible;
        return $bible;
    }

    /**
     * Check if message is Afrikaans
     */
    private static function isAfrikaans($text)
    {
        $afWords = ['die', 'van', 'het', 'wat', 'ek', 'jy', 'ons', 'hulle', 'nie', 'sal', 'kan', 'moet', 'asb', 'asseblief', 'dankie', 'hoekom', 'waarom', 'waar', 'hoe', 'wanneer'];
        $text = strtolower($text);
        $count = 0;

        foreach ($afWords as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $text)) {
                $count++;
            }
        }

        return $count >= 2;
    }

    /**
     * Extract Bible references from text
     * Examples: "Johannes 3:16", "Joh 3:16", "John 3:16", "Gen 1:1-3"
     */
    private static function extractReferences($text)
    {
        $references = [];

        // Book name mappings (Afrikaans and English)
        $bookPatterns = [
            // Afrikaans
            'genesis|gen', 'eksodus|eks', 'levitikus|lev', 'numeri|num', 'deuteronomium|deut',
            'josua|jos', 'rigters|rig', 'rut', 'samuel|sam', 'konings|kon', 'kronieke|kron',
            'esra', 'nehemia|neh', 'ester|est', 'job', 'psalms?|ps', 'spreuke|spr',
            'prediker|pred', 'hooglied|hoogl', 'jesaja|jes', 'jeremia|jer', 'klaagliedere|klaag',
            'esegiel|eseg', 'daniel|dan', 'hosea|hos', 'joel', 'amos', 'obadja|obad',
            'jona', 'miga', 'nahum|nah', 'habakuk|hab', 'sefanja|sef', 'haggai|hag',
            'sagaria|sag', 'maleagi|mal',
            'matteus|matt?', 'markus|mark?', 'lukas|luk', 'johannes|joh',
            'handelinge|hand', 'romeine|rom', 'korintiers|kor', 'galasiers|gal',
            'efesiers|ef', 'filippense|fil', 'kolossense|kol', 'tessalonisense|tess',
            'timoteus|tim', 'titus|tit', 'filemon|filem', 'hebreers|heb',
            'jakobus|jak', 'petrus|pet', 'judas|jud', 'openbaring|openb',
            // English additions
            'exodus|exod?', 'leviticus', 'numbers', 'deuteronomy', 'joshua', 'judges',
            'ruth', 'kings', 'chronicles', 'ezra', 'nehemiah', 'esther', 'proverbs|prov',
            'ecclesiastes|eccl', 'song of solomon|song', 'isaiah|isa', 'jeremiah',
            'lamentations|lam', 'ezekiel|ezek', 'hosea', 'obadiah', 'jonah', 'micah',
            'nahum', 'habakkuk', 'zephaniah|zeph', 'haggai', 'zechariah|zech', 'malachi',
            'matthew', 'luke', 'john', 'acts', 'romans', 'corinthians', 'galatians',
            'ephesians', 'philippians', 'colossians', 'thessalonians', 'timothy',
            'philemon', 'hebrews', 'james', 'peter', 'jude', 'revelation|rev'
        ];

        $bookPattern = implode('|', $bookPatterns);

        // Match patterns like "Johannes 3:16" or "Joh. 3:16-18" or "1 Kor 13:4"
        $pattern = '/\b([12]?\s*(?:' . $bookPattern . ')\.?)\s*(\d+)\s*:\s*(\d+)(?:\s*-\s*(\d+))?\b/i';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $references[] = [
                    'book' => trim($match[1]),
                    'chapter' => (int)$match[2],
                    'verse_start' => (int)$match[3],
                    'verse_end' => isset($match[4]) ? (int)$match[4] : (int)$match[3]
                ];
            }
        }

        return $references;
    }

    /**
     * Look up a specific verse in the Bible data
     */
    private static function lookupVerse($bible, $ref)
    {
        // Bible JSON structure varies, try common formats
        $book = self::normalizeBookName($ref['book']);
        $chapter = $ref['chapter'];
        $verseStart = $ref['verse_start'];
        $verseEnd = $ref['verse_end'];

        // Try different JSON structures
        // Structure 1: { "books": { "Genesis": { "chapters": { "1": { "verses": { "1": "text" } } } } } }
        if (isset($bible['books'][$book]['chapters'][$chapter]['verses'])) {
            $verses = $bible['books'][$book]['chapters'][$chapter]['verses'];
            $texts = [];
            for ($v = $verseStart; $v <= $verseEnd; $v++) {
                if (isset($verses[$v])) {
                    $texts[] = $verses[$v];
                }
            }
            if (!empty($texts)) {
                return [
                    'reference' => "{$book} {$chapter}:{$verseStart}" . ($verseEnd > $verseStart ? "-{$verseEnd}" : ""),
                    'text' => implode(' ', $texts)
                ];
            }
        }

        // Structure 2: flat array with book/chapter/verse keys
        if (isset($bible['verses'])) {
            $texts = [];
            foreach ($bible['verses'] as $verse) {
                if (strtolower($verse['book'] ?? '') === strtolower($book) &&
                    ($verse['chapter'] ?? 0) == $chapter &&
                    ($verse['verse'] ?? 0) >= $verseStart &&
                    ($verse['verse'] ?? 0) <= $verseEnd) {
                    $texts[] = $verse['text'] ?? '';
                }
            }
            if (!empty($texts)) {
                return [
                    'reference' => "{$book} {$chapter}:{$verseStart}" . ($verseEnd > $verseStart ? "-{$verseEnd}" : ""),
                    'text' => implode(' ', $texts)
                ];
            }
        }

        return null;
    }

    /**
     * Normalize book name to standard form
     */
    private static function normalizeBookName($name)
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]/', '', $name);

        $mappings = [
            'gen' => 'Genesis', 'genesis' => 'Genesis',
            'eks' => 'Eksodus', 'exod' => 'Exodus', 'exodus' => 'Exodus',
            'lev' => 'Levitikus', 'leviticus' => 'Leviticus',
            'num' => 'Numeri', 'numbers' => 'Numbers',
            'deut' => 'Deuteronomium', 'deuteronomy' => 'Deuteronomy',
            'ps' => 'Psalms', 'psalm' => 'Psalms', 'psalms' => 'Psalms',
            'spr' => 'Spreuke', 'prov' => 'Proverbs', 'proverbs' => 'Proverbs',
            'matt' => 'Matteus', 'mat' => 'Matteus', 'matthew' => 'Matthew',
            'mark' => 'Markus', 'markus' => 'Markus',
            'luk' => 'Lukas', 'luke' => 'Luke', 'lukas' => 'Lukas',
            'joh' => 'Johannes', 'john' => 'John', 'johannes' => 'Johannes',
            'hand' => 'Handelinge', 'acts' => 'Acts',
            'rom' => 'Romeine', 'romans' => 'Romans',
            'kor' => 'Korintiers', 'cor' => 'Corinthians',
            '1kor' => '1 Korintiers', '1cor' => '1 Corinthians',
            '2kor' => '2 Korintiers', '2cor' => '2 Corinthians',
            'ef' => 'Efesiers', 'eph' => 'Ephesians', 'ephesians' => 'Ephesians',
            'fil' => 'Filippense', 'phil' => 'Philippians',
            'kol' => 'Kolossense', 'col' => 'Colossians',
            'heb' => 'Hebreers', 'hebrews' => 'Hebrews',
            'jak' => 'Jakobus', 'james' => 'James',
            'pet' => 'Petrus', 'peter' => 'Peter',
            '1pet' => '1 Petrus', '1peter' => '1 Peter',
            '2pet' => '2 Petrus', '2peter' => '2 Peter',
            'openb' => 'Openbaring', 'rev' => 'Revelation', 'revelation' => 'Revelation'
        ];

        return $mappings[$name] ?? ucfirst($name);
    }

    /**
     * Extract keywords from message for searching
     */
    private static function extractKeywords($text)
    {
        // Remove common words
        $stopwords = ['die', 'van', 'het', 'wat', 'ek', 'jy', 'ons', 'is', 'en', 'of', 'the', 'a', 'an', 'and', 'or', 'to', 'in', 'on', 'for', 'with', 'my', 'me', 'i', 'you', 'we'];

        $words = preg_split('/\s+/', strtolower($text));
        $keywords = [];

        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (strlen($word) > 3 && !in_array($word, $stopwords)) {
                $keywords[] = $word;
            }
        }

        return array_slice($keywords, 0, 5);
    }

    /**
     * Search Bible by keywords
     */
    private static function searchByKeywords($bible, $keywords, $limit = 3)
    {
        if (empty($keywords)) {
            return [];
        }

        $results = [];

        // Search through verses
        if (isset($bible['verses'])) {
            foreach ($bible['verses'] as $verse) {
                $text = strtolower($verse['text'] ?? '');
                $score = 0;

                foreach ($keywords as $keyword) {
                    if (strpos($text, $keyword) !== false) {
                        $score++;
                    }
                }

                if ($score > 0) {
                    $results[] = [
                        'reference' => ($verse['book'] ?? '') . ' ' . ($verse['chapter'] ?? '') . ':' . ($verse['verse'] ?? ''),
                        'text' => $verse['text'] ?? '',
                        'score' => $score
                    ];
                }
            }
        }

        // Sort by score and return top results
        usort($results, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return array_slice($results, 0, $limit);
    }

    /**
     * Get bibles path
     */
    private static function getBiblesPath()
    {
        if (!self::$biblesPath) {
            self::$biblesPath = __DIR__ . '/../bibles';
        }
        return self::$biblesPath;
    }

    /**
     * Clear cache
     */
    public static function clearCache()
    {
        self::$bibles = [];
    }
}
