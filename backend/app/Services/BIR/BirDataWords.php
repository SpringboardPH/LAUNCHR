<?php

namespace App\Services\BIR;

/**
 * What a message says in so many words, for BirDataIntent to check the model's labels against:
 * a group of employees, an amount, a condition, a status, a company detail, a follow-up, and the
 * numbers it gives. Typed slips are allowed where a word is long enough to tell apart.
 */
final class BirDataWords
{
    /** Narrowing the question just answered, rather than replacing its conditions: "only the taxable ones", "those over 30k". */
    public const NARROW = '/\b(only|just|lang|those|them|of them|sa kanila|among them|sa mga iyon|alin sa kanila)\b/i';

    /** One at the top: "who is the newest?", "the oldest hire". Ties at the top are all named. */
    public const ONE = '/\b(newest|latest|oldest|earliest|first|last|most recent(?:ly)?|longest|pinaka\w*|highest|lowest|biggest|smallest|most|least)\b/i';

    /** A condition in so many words: a comparison, or a number that isn't a year. */
    public const CONDITION = '/\b(higher|more|greater|over|above|exceed\w*|less|lower|below|under|than|between|at least|at most|mahigit|higit|lampas|kulang|mababa|mataas|before|after|since|bago|pagkatapos|contains?|named|starting)\b|\d[\d,]*(?:\.\d+)?\s*(?:k|m)\b|\d{1,3}(?:,\d{3})+|\b(?!(?:19|20)\d{2}\b)\d{3,}\b/i';

    /**
     * A word for a group of employees; without one (or one of GROUP_SPELLED typed with a slip), a
     * group the model gives is dropped. "tax" alone isn't one: "total tax withheld" names no group.
     */
    public const GROUP_WORDS = '/taxable|minimum|mwe|min\.?\s*wage|exempt|250|\bpays?\s+(?:any\s+|income\s+|withholding\s+)?(?:tax|buwis)|nagbabayad\s+ng\s+(?:tax|buwis)/i';

    public const GROUP_SPELLED = ['taxable', 'nontaxable', 'minimum', 'exempt', 'exempted'];

    /** A word for an amount; an amount the model gives with none of these is a guess, and asked about (BirDataDoubt). */
    public const AMOUNT_WORDS = '/salar|\bpay|paid|compensat|tax|withh|earn|sahod|sweldo|kita|binayad|contribut|\bsss\b|philhealth|pag-?ibig|13th|thirteenth|bonus|benefit|\brate|wage|income|deduct|kaltas|buwis|\bmake\b|\bmade\b|\bmakes\b|kinita|\bg[ia]ve|\bgiven\b|\bget\b|\bgot\b|receiv|bigay|natanggap|nakuha/i';

    /** The words a company detail needs: "who is our CEO?" is not the company's name. */
    public const COMPANY_WORDS = ['name' => '/\bname|pangalan|called\b/i', 'tin' => '/\btins?\b/i', 'rdo_code' => '/\brdo\b/i', 'address' => '/address|tirahan|located|location/i', 'zip' => '/\bzip|postal/i',
        'email' => '/e-?mail/i', 'phone' => '/phone|contact|telephone|\btel\b|landline|mobile|numero/i'];

    /** The same words typed with a slip ("adress", "pangaln"). */
    public const COMPANY_SPELLED = ['name' => ['pangalan'], 'address' => ['address', 'tirahan', 'location'], 'zip' => ['postal'], 'email' => ['email'], 'phone' => ['phone', 'contact', 'telephone']];

    /** "how about 2024?", "e yung minimum wage?": a follow-up to the question just answered. */
    public const FOLLOW_UP = '/^\s*(?:(?:and\s+)?(?:how|what)\s+about|and\s+(?:for|in|the|who|how)|e\s|eh\s|ehh?\s|paano\s+(?:naman\s+)?(?:sa|kung|yung)|ano\s+naman|sa\s|how\s+many\s+of\s+them|(?:only|just)\s+(?:the|those|them|ones)|those\s+(?:who|with|hired|that|earning)|yung\s|ilan\s+sila|sino\s+naman)|^\s*(?:how\s+many|ilan|count|bilang)\s*\??\s*$|^\s*(?:and\s+)?(?:the\s+)?(?:total|average|avg|sum|kabuuan|highest|lowest)\s*\??\s*$|^\s*(?:and\s+)?(?:why|bakit|how\s+come|bakit\s+naman|paano\s+nangyari)\s*\??\s*$|\b(?:of|among|from)\s+(?:them|those|these)\b|\bsa\s+(?:kanila|mga\s+iyon|mga\s+ito)\b/iu';

    /** A status as the model or the user may say it. */
    public const STATUS_SAID = ['filed' => 'finalized', 'final' => 'finalized', 'submitted' => 'pending', 'for_review' => 'pending', 'under_review' => 'pending', 'unfiled' => 'not_filed', 'open' => 'not_filed'];

    /** Every amount the message gives: "500,000" and "500000", "30k" as 30000, "1.5m" as 1500000. */
    public static function numbersSaid(string $message): array
    {
        preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*(k|m|thousand|million|libo)?(?![\d,])/i', $message, $m, PREG_SET_ORDER);

        $numbers = array_map(fn (array $n) => (float) str_replace(',', '', $n[1]) * match (strtolower($n[2] ?? '')) {
            'k', 'thousand', 'libo' => 1000,
            'm', 'million' => 1000000,
            default => 1,
        }, $m);
        // "one million", "half a million", "isang milyon"
        preg_match_all('/\b(?:(half)\s+a|a|one|isang)\s+(million|milyon)\b/i', $message, $w, PREG_SET_ORDER);

        return [...$numbers, ...array_map(fn (array $x) => $x[1] !== '' ? 500000.0 : 1000000.0, $w)];
    }

    /** A word for a group of employees, or one typed with a slip ("taxbale", "minimun", "exmept"). */
    public static function namesAGroup(string $message): bool
    {
        $message = preg_replace(self::GROUPLESS, ' ', $message);
        if (preg_match(self::GROUP_WORDS, $message)) {
            return true;
        }
        foreach (preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            foreach (self::GROUP_SPELLED as $group) {
                if (strlen($word) >= 5 && BirMessageFacts::slips($word, $group) <= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** "which forms are filed?", "not filed yet", "waiting for review": a status the message says. */
    public static function statusSaid(string $message): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(?:not|hindi pa|di pa|un)\s*-?\s*filed\b|\bnot yet filed\b|\b(?:to be|to|for) fil(?:ed|e|ing)\b/i', $message) => 'not_filed',
            (bool) preg_match('/\bfiled\b|\bnai-?file\b|\b(?:did|have|has)\s+(?:we|you|they|i)\s+file\b/i', $message) => 'finalized',
            (bool) preg_match('/\b(?:waiting for|for|under|pending) review\b|\bpending\b/i', $message) => 'pending',
            (bool) preg_match('/\breturned\b|\bsent back\b|\bibinalik\b/i', $message) => 'returned',
            (bool) preg_match('/\bapproved\b/i', $message) => 'approved',
            (bool) preg_match('/\bdrafts?\b/i', $message) => 'draft',
            default => null,
        };
    }

    /**
     * "who is the highest?", "who has the most?", "what is the total?": a highest, lowest, total or
     * average that names no amount (BirDataDoubt then asks which). Null when it names one, or none.
     */
    public static function vague(string $message): ?string
    {
        if (preg_match(self::AMOUNT_WORDS, $message) || preg_match('/hire|form|1601|2316|recent|latest|newest/i', $message)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/\b(highest|most|top|biggest|largest|pinakamataas|pinakamalaki|pinakamarami)\b|number\s*(?:one|1)\b|\bno\.?\s*1\b|#1\b|numero\s+uno/i', $message) => 'highest',
            (bool) preg_match('/\b(lowest|least|smallest|pinakamababa|pinakamaliit|pinakakaunti)\b/i', $message) => 'lowest',
            (bool) preg_match('/\b(average|karaniwan)\b/i', $message) => 'average',
            (bool) preg_match('/\bmedian\b/i', $message) => 'median',
            (bool) preg_match('/\b(total|kabuuan|lahat-lahat)\b/i', $message) => 'total',
            default => null,
        };
    }

    /** True when the message states a condition: a comparison, or an amount that isn't a year. */
    public static function hasCondition(string $message): bool
    {
        return preg_match(self::CONDITION, $message) === 1;
    }

    /** True when the message has a word for this company detail, typed with at most one slip. */
    public static function saysCompanyWord(string $detail, string $message): bool
    {
        if (preg_match(self::COMPANY_WORDS[$detail], $message)) {
            return true;
        }
        foreach (preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            foreach (self::COMPANY_SPELLED[$detail] ?? [] as $right) {
                if (strlen($word) >= 5 && BirMessageFacts::slips($word, $right) <= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** True for a message that reads as a follow-up to the question just answered. */
    public static function followsUp(string $message): bool
    {
        return preg_match(self::FOLLOW_UP, $message) === 1;
    }

    /** The group of employees a message names in so many words: "minimum wage", "taxable", "not taxable", "exempt". */
    public static function groupSaid(string $message): ?string
    {
        return match (true) {
            (bool) preg_match('/minimum|mwe|min\.?\s*wage/i', $message) => 'minimum_wage',
            (bool) preg_match('/exempt|250/i', $message) => 'exempt_250k',
            (bool) preg_match('/\b(?:non-?|not |hindi )taxable\b|nontaxable/i', $message) => 'not_taxable',
            (bool) preg_match('/\btaxable\b/i', $message) => 'taxable',
            default => null,
        };
    }

    /** Month by month: "per month", "which month", "kada buwan". */
    public const PER_MONTH = '/\b(?:which|what|anong|aling?)\s+(?:month|buwan)|\bper\s+month|\beach\s+month|\bby\s+month|\bmonth\s+by\s+month|\bmonthly\s+(?:total|breakdown|figures)|\bkada\s+buwan|\bbawat\s+buwan|\bevery\s+month/i';

    /** A word for employees as such: "list all employees", "who are the staff", "ilan tayo". Without one, nothing to list. */
    public const EMPLOYEE_WORDS = '/employee|empleyado|staff|\btao\b|people|everyone|everybody|lahat|\blist\b|workers?|team|tauhan|\bilan\b|how many|\bcount\b|sinu-?sino|sino-?sino|personnel|crew|names?|tayo|kami|natin|\bwho\b|\bsino\b/i';

    /** The newest or the oldest by hire date, with no sort the model gave: "who's the second newest?". */
    public const HIRED_ORDER = ['desc' => '/newest|latest hire|most recent|pinakabago|bagong pasok|recently hired|youngest in the company|hired last|last (?:one )?(?:to be )?hired|last to join|joined last|pinakahuling|huling (?:na-?)?(?:hire|pumasok)/i', 'asc' => '/oldest|earliest|longest|pinakamatagal|pinakauna|senior|first hired|hired first|first to join|joined first|been here the longest/i'];

    /** Details a message asks to be shown: "everyone's position and hire date", "lahat ng TIN". */
    public const SHOWN = ['position' => '/position|job|title|trabaho/i', 'hire_date' => '/hire|hired|joined|start date/i', 'department' => '/department|dept/i',
        'tin' => '/\btins?\b/i', 'salary' => '/salar|sahod|sweldo/i', 'status' => '/\bstatus\b|active/i', 'rate_type' => '/daily|monthly|pay basis/i',
        'group' => '/\btax\s+(?:groups?|status|categor(?:y|ies))\b|\bgroups?\b|\bcategor(?:y|ies)\b|\bclassification\b|\bkategorya\b/i', 'email' => '/e-?mails?\b/i', 'phone' => '/\bphones?\b|mobile|contact\s+numbers?|cellphone|cp\s+numbers?/i'];

    /** What payroll paid, as details to list for everyone ("everyone's TIN and compensation in 2025"). */
    public const PAID_SHOWN = ['compensation' => '/(?<!taxable )(?<!taxable-)\bcompensat|\bgross\s+pay|\bkinita\b/i', 'tax_withheld' => '/\btax(?:es)?\b(?!\s+(?:group|status|code|advice))|withholding|\bwithheld\b|\bbuwis\b/i',
        'thirteenth_month' => '/13th|thirteenth|\b13\s*-?\s*months?\b|\bbonus/i', 'contributions' => '/contribut|\bsss\b|philhealth|pag-?ibig/i',
        'taxable_compensation' => '/(?<!non-)(?<!non )\btaxable\s+(?:compensation|pay|income)/i', 'non_taxable_compensation' => '/\bnon-?\s?taxable\s+(?:compensation|pay|income)/i'];

    /** "taxable compensation", "non-taxable pay": amounts, never the group. */
    private const GROUPLESS = '/\b(?:non-?\s?)?taxable\s+(?:compensation|pay|income)\b/i';

    /** A total asked for, not each one's: "total compensation of all employees", "how much did everyone earn". */
    public const TOTAL = '/\b(?:total|sum|altogether|combined|together|kabuuan|how\s+much|magkano)\b/i';

    /**
     * The details a message names (SHOWN, and PAID_SHOWN for what payroll paid), in the order
     * named: "TIN and hire date" is the TIN, then the hire date.
     *
     * @return list<string>
     */
    public static function detailsNamed(string $message): array
    {
        $at = array_filter(array_map(fn (string $words) => preg_match($words, $message, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null, self::SHOWN + self::PAID_SHOWN), fn ($o) => $o !== null);
        asort($at);

        return array_keys($at);
    }

    /** Asking about everyone: "show everyone's …", "list all …", "lahat ng …". */
    public const EVERYONE = '/everyone|everybody|\ball\b|lahat|each|every\b|employees|staff|list\b|show\b|\b(?:tins|salaries|positions|departments|hire\s+dates|e-?mails|phone\s+numbers)\b/i';

    /** Counting by a detail: "which department has the most employees?", "how many per position". */
    public const PER_FIELD = ['department' => '/\b(?:which|what|anong|aling?)\s+(?:department|dept)|\b(?:per|by|each|every|bawat|kada)\s+(?:department|dept)/i',
        'position' => '/\b(?:which|what|anong|aling?)\s+(?:position|job)|\b(?:per|by|each|every|bawat|kada)\s+(?:position|job)/i'];

    /** In every month of the period: "who had tax withheld every month of 2025?". */
    public const EVERY_MONTH = '/\b(?:every|each|all)\s+months?\b|\bbawat\s+buwan\b|\bbuong\s+taon\b|\ball\s+year\b/i';

    /** A monthly average: "average monthly compensation", "per month on average". */
    public const MONTHLY = '/\bmonthly\b|\bper\s+month\b|\ba\s+month\b|\bkada\s+buwan\b|\bbuwanang?\b/i';

    /** "who else", "other than her": the person in view is left out. */
    public const ELSE = '/\b(?:else|other than|besides|bukod|iba pa)\b/i';

    /**
     * One comparison with one amount, in so many words: "more than 26k" is ['gt', 26000.0], "at most
     * 18,000" ['lte', 18000.0]. Null when there is none, or more than one.
     */
    public static function comparisonSaid(string $message): ?array
    {
        $ops = ['gte' => 'at least|or more|pataas', 'lte' => 'at most|or less|pababa', 'gt' => 'more than|over|above|greater than|higher than|exceeding|mahigit(?: sa)?|lagpas(?: sa)?|lampas(?: sa)?',
            'lt' => 'less than|under|below|lower than|kulang(?: sa)?|mas mababa sa'];
        $found = [];
        foreach ($ops as $op => $words) {
            if (preg_match_all('/\b(?:' . $words . ')\s+(?:₱|php|p)?\s*(\d[\d,]*(?:\.\d+)?\s*(?:k|m|thousand|million)?)/iu', $message, $m)) {
                foreach ($m[1] as $n) {
                    $found[] = [$op, self::numbersSaid($n)[0] ?? null];
                }
            }
            if (in_array($op, ['gte', 'lte'], true) && preg_match_all('/(\d[\d,]*(?:\.\d+)?\s*(?:k|m)?)\s+(?:' . $words . ')\b/iu', $message, $m)) {
                foreach ($m[1] as $n) {
                    $found[] = [$op, self::numbersSaid($n)[0] ?? null];
                }
            }
        }

        return count($found) === 1 && $found[0][1] !== null ? $found[0] : null;
    }

    /** "monthly paid", "arawan": the pay basis a message names. */
    public static function rateSaid(string $message): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(?:monthly[- ]?paid|paid monthly|monthly rate|on a monthly|monthly employees|buwanan)\b/i', $message) => 'monthly',
            (bool) preg_match('/\b(?:daily[- ]?paid|paid daily|daily rate|per day|on a daily|daily employees|arawan)\b/i', $message) => 'daily',
            default => null,
        };
    }

    /** Asking for advice, not for what the records hold. */
    private const ADVICE = '/\b(should|dapat|bracket|compute|calculat|lower|reduce|avoid|legal|estimate|will|would|strategy|paano bawasan)\b/i';

    /**
     * A question read from the message alone when the model gave none: a total, average, highest or
     * lowest of an amount it names ("the average tax of everyone in December 2025"). Never advice.
     */
    public static function guessQuestion(string $message): ?array
    {
        if (preg_match(self::ADVICE, $message)) {
            return null;
        }
        $measure = match (true) {
            (bool) preg_match('/\bmedian\b/i', $message) => 'median',
            (bool) preg_match('/\b(?:average|avg|karaniwan)\b/i', $message) => 'average',
            (bool) preg_match('/\b(?:total|sum|kabuuan|in all)\b/i', $message) => 'total',
            (bool) preg_match('/\b(?:highest|most|top|biggest|pinakamataas|pinakamalaki)\b/i', $message) => 'highest',
            (bool) preg_match('/\b(?:lowest|least|smallest|pinakamababa|pinakamaliit)\b/i', $message) => 'lowest',
            default => null,
        };
        $amount = match (true) {
            (bool) preg_match('/\bsss\b|philhealth|pag-?ibig|contribut|kontribusyon/i', $message) => 'contributions',
            (bool) preg_match('/13th|thirteenth|bonus|benefit/i', $message) => 'thirteenth_month',
            (bool) preg_match('/\btax|withh|kaltas|buwis/i', $message) => 'tax_withheld',
            (bool) preg_match('/salar|sahod|sweldo|\brate\b/i', $message) => 'salary',
            (bool) preg_match('/\bpay\b|paid|earn|compensat|kita/i', $message) => 'compensation',
            default => null,
        };

        // "what's Bea's bonus this year?": one named employee's amount.
        if ($measure === null && $amount !== null && preg_match('/^\s*(?:what|how\s+much|magkano|ano|show|tell)\b/i', $message)
            && ($named = app(BirEmployeeResolver::class)->namedIn($message))->count() === 1) {
            $first = $named->first()->first_name;

            return ['topic' => 'amounts', 'measure' => 'total', 'amount' => $amount, 'employee' => stripos($message, $first) !== false ? $first : $named->first()->last_name];
        }

        return $measure !== null && $amount !== null ? ['topic' => 'amounts', 'measure' => $measure, 'amount' => $amount] : null;
    }

    /** "waiting for review or approved": every form status named, when more than one is. */
    public static function statusesSaid(string $message): array
    {
        $found = array_values(array_filter([
            preg_match('/\b(?:waiting for|for|under|pending)\s+review\b|\bpending\b|\bsubmitted\b/i', $message) ? 'pending' : null,
            preg_match('/\bapproved\b/i', $message) ? 'approved' : null,
            preg_match('/\bfiled\b/i', $message) && !preg_match('/\bnot\s+(?:yet\s+)?filed\b/i', $message) ? 'finalized' : null,
            preg_match('/\bdrafts?\b/i', $message) ? 'draft' : null,
            preg_match('/\breturned\b|\bsent back\b/i', $message) ? 'returned' : null,
        ]));

        return count($found) > 1 ? $found : [];
    }

    /** Sorting everyone, not picking the one at the top: "highest to lowest", "rank them", "sort by salary". */
    public const SORTING = '/\b(sort|sorted|order|ordered|rank|ranked|arrange)\b|\b(?:to|from|hanggang)\s+(?:the\s+)?(?:highest|lowest|newest|oldest|pinaka\w*)/i';

    /** Things these records don't measure: never offered an amount instead. */
    public const UNKNOWN = '/overtime|\bhours?\b|\bleaves?\b|absen|\blate\b|tardi|attendance|undertime|\bsales\b|commission|performance|rating|\bage\b|birthday|\bloans?\b|night\s*diff/i';

    /**
     * The months a question covers when it names more than one: "January to March 2026" (a range),
     * "January and March" (those two), "Q1" or "first quarter". [] when it names one month or none.
     *
     * @return list<int>
     */
    public static function monthsAsked(string $message): array
    {
        if (preg_match('/\bQ([1-4])\b|\b(first|second|third|fourth|1st|2nd|3rd|4th)\s+quarter\b/i', $message, $q)) {
            $n = $q[1] !== '' ? (int) $q[1] : ['first' => 1, '1st' => 1, 'second' => 2, '2nd' => 2, 'third' => 3, '3rd' => 3, 'fourth' => 4, '4th' => 4][strtolower($q[2])];

            return range($n * 3 - 2, $n * 3);
        }
        $said = BirMessageFacts::monthsSaid($message);
        if (count($said) < 2) {
            return [];
        }
        sort($said);

        return preg_match('/\b(to|through|thru|until|till|hanggang|up to|between|from)\b|\s[-–]\s/iu', $message) ? range(min($said), max($said)) : $said;
    }

    /** "the second highest", "pangatlo": which place, from 2; null for the top. "second quarter" is a period. */
    public static function nth(string $message): ?int
    {
        $not = '(?!\s+(?:quarter|half|kwarter))';

        return match (true) {
            (bool) preg_match("/\\b(second|2nd|ikalawa\\w*|pangalawa\\w*)\\b{$not}/i", $message) => 2,
            (bool) preg_match("/\\b(third|3rd|ikatlo\\w*|pangatlo\\w*)\\b{$not}/i", $message) => 3,
            (bool) preg_match("/\\b(fourth|4th|ikaapat\\w*|pang-?apat\\w*)\\b{$not}/i", $message) => 4,
            (bool) preg_match("/\\b(fifth|5th|ikalima\\w*|panlima\\w*)\\b{$not}/i", $message) => 5,
            default => null,
        };
    }

    /**
     * Every group a message names: "how many minimum wage earners and how many taxable" is two.
     * "not taxable" and "non-taxable" are not also "taxable".
     *
     * @return list<string>
     */
    public static function groupsSaid(string $message): array
    {
        $m = preg_replace(self::GROUPLESS, ' ', mb_strtolower($message)); // "taxable compensation" is an amount

        return array_values(array_filter([
            preg_match('/minimum|mwe|min\.?\s*wage/', $m) ? 'minimum_wage' : null,
            preg_match('/exempt|250/', $m) ? 'exempt_250k' : null,
            preg_match('/\b(?:non-?|not |hindi )taxable|nontaxable/', $m) ? 'not_taxable' : null,
            preg_match('/(?<!non-)(?<!non)(?<!not )(?<!hindi )\btaxable\b/', $m) ? 'taxable' : null,
        ]));
    }
}
