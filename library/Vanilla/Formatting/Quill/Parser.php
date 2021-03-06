<?php
/**
 * @author Adam (charrondev) Charron <adam.c@vanillaforums.com>
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license https://opensource.org/licenses/GPL-2.0 GPL-2.0
 */

namespace Vanilla\Formatting\Quill;

use Vanilla\Formatting\Quill\Blots;
use Vanilla\Formatting\Quill\Formats;
use Vanilla\Formatting\Quill\Blots\AbstractBlot;

/**
 * Class for parsing Quill Deltas into BlotGroups.
 *
 * @see https://github.com/quilljs/delta Information on quill deltas.
 */
class Parser {

    const BREAK_OPERATION = [
        "breakpoint" => true,
    ];

    const LINE_BLOTS = [
        Blots\Lines\BlockquoteLineBlot::class,
        Blots\Lines\ListLineBlot::class,
        Blots\Lines\SpoilerLineBlot::class,
    ];

    /** @var string[] The registered blot classes */
    private $blotClasses = [];

    /** @var string[] The registered formats classes */
    private $formatClasses = [];

    /**
     * Add a new embed type.
     *
     * @param string $blotClass The blot to register.
     *
     * @see AbstractBlot
     * @return $this
     */
    public function addBlot(string $blotClass) {
        $this->blotClasses[] = $blotClass;

        return $this;
    }

    /**
     * Register all of the built in blots and formats to parse. Primarily for use in bootstrapping.
     *
     * The embeds NEED to be first here, otherwise something like a blockquote with only a mention in it will
     * match only as a blockquote instead of as a mention.
     */
    public function addCoreBlotsAndFormats() {
        $this
            ->addBlot(Blots\Embeds\ExternalBlot::class)
            ->addBlot(Blots\Embeds\MentionBlot::class)
            ->addBlot(Blots\Embeds\EmojiBlot::class)
            ->addBlot(Blots\Lines\SpoilerLineBlot::class)
            ->addBlot(Blots\Lines\BlockquoteLineBlot::class)
            ->addBlot(Blots\Lines\ListLineBlot::class)
            ->addBlot(Blots\CodeBlockBlot::class)
            ->addBlot(Blots\HeadingBlot::class)
            ->addBlot(Blots\TextBlot::class)// This needs to be the last one!!!
            ->addFormat(Formats\Link::class)
            ->addFormat(Formats\Bold::class)
            ->addFormat(Formats\Italic::class)
            ->addFormat(Formats\Code::class)
            ->addFormat(Formats\Strike::class)
        ;
    }

    /**
     * Add a new embed type.
     *
     * @param string $formatClass The Format class to register.
     *
     * @see AbstractFormat
     * @return $this
     */
    public function addFormat(string $formatClass) {
        $this->formatClasses[] = $formatClass;

        return $this;
    }

    /**
     * Parse the operations into an array of Groups.
     *
     * @return BlotGroup[]
     */
    public function parse(array $operations): array {
        $operations = $this->splitPlainTextNewlines($operations);
        $this->insertBreakPoints($operations);
        return $this->createBlotGroups($operations);
    }

    /**
     * Parse out the usernames of everyone mentioned in a post.
     *
     * @param array $operations
     * @return string[]
     */
    public function parseMentionUsernames(array $operations): array {
        if (!in_array(Blots\Embeds\MentionBlot::class, $this->blotClasses)) {
            return [];
        }

        $blotGroups = $this->parse($operations);
        $mentionUsernames = [];
        foreach ($blotGroups as $blotGroup) {
            $mentionUsernames = array_merge($mentionUsernames, $blotGroup->getMentionUsernames());
        }

        return $mentionUsernames;
    }

    /**
     * Get the matching blot for a sequence of operations. Returns the default if no match is found.
     *
     * @param array $currentOp The current operation.
     * @param array $previousOp The next operation.
     * @param array $nextOp The previous operation.
     *
     * @return AbstractBlot
     */
    public function getBlotForOperations(array $currentOp, array $previousOp = [], array $nextOp = []): AbstractBlot {
        // Fallback to a TextBlot if possible. Otherwise we fallback to rendering nothing at all.
        $blotClass = Blots\TextBlot::matches([$currentOp]) ? Blots\TextBlot::class : Blots\NullBlot::class;
        foreach ($this->blotClasses as $blot) {
            // Find the matching blot type for the current, last, and next operation.
            if ($blot::matches([$currentOp, $nextOp])) {
                $blotClass = $blot;
                break;
            }
        }

        return new $blotClass($currentOp, $previousOp, $nextOp);
    }

    /**
     * Get the matching format for a sequence of operations if applicable.
     *
     * @param array $currentOp The current operation.
     * @param array $previousOp The next operation.
     * @param array $nextOp The previous operation.
     *
     * @return AbstractForm[] The formats matching the given operations.
     */
    public function getFormatsForOperations(array $currentOp, array $previousOp = [], array $nextOp = []) {
        $formats = [];
        foreach ($this->formatClasses as $format) {
            if ($format::matches([$currentOp])) {
                /** @var Formats\AbstractFormat $formatInstance */
                $formats[] = new $format($currentOp, $previousOp, $nextOp);
            }
        }

        return $formats;
    }

    /**
     * Call parse and then simplify each blotGroup into test data.
     *
     * @param array $ops The ops to parse
     */
    public function parseIntoTestData(array $ops): array {
        $parseData = $this->parse($ops);
        $groupData = [];
        foreach ($parseData as $blotGroup) {
            $groupData[] = $blotGroup->getTestData();
        }

        return $groupData;
    }

    /**
     * Create blot groups out of an array of operations.
     *
     * @param array $operations The pre-parsed operations
     *
     * @return BlotGroup[]
     */
    private function createBlotGroups(array $operations): array {
        $groups = [];
        $operationLength = count($operations);
        $group = new BlotGroup();

        for ($i = 0; $i < $operationLength; $i++) {
            $currentOp = $operations[$i];

            // In event of break blots we want to clear the group if applicable and the skip to the next item.
            if ($currentOp === self::BREAK_OPERATION) {
                if (!$group->isEmpty()) {
                    $groups[] = $group;
                    $group = new BlotGroup();
                }
                continue;
            }

            $previousOp = $operations[$i - 1] ?? [];
            $nextOp = $operations[$i + 1] ?? [];
            $blotInstance = $this->getBlotForOperations($currentOp, $previousOp, $nextOp);

            // Ask the blot if it should close the current group.
            if (($blotInstance->shouldClearCurrentGroup($group)) && !$group->isEmpty()) {
                $groups[] = $group;
                $group = new BlotGroup();
            }

            $group->pushBlot($blotInstance);

            // Some block type blots get a group all to themselves.
            if ($blotInstance->isOwnGroup() && !$group->isEmpty()) {
                $groups[] = $group;
                $group = new BlotGroup();
            }

            // Check with the blot if absorbed the next operation. If it did we don't want to iterate over it.
            if ($blotInstance->hasConsumedNextOp()) {
                $i++;
            }
        }
        if (!$group->isEmpty()) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Determine if an operation is a text insert with no attributes.
     *
     * @param array $op The operation
     *
     * @return bool
     */
    private function isOperationBareInsert(array $op) {
        return !array_key_exists("attributes", $op)
            && array_key_exists("insert", $op)
            && is_string($op["insert"]);
    }

    /**
     * Determine if an operation represents the terminating operation ("eg, nextBlot") of an LineBlot).
     *
     * @param array $operation The operation
     *
     * @return bool
     */
    private function isOpALineBlotTerminator(array $operation): bool {
        $validLineBlotClasses = array_intersect(static::LINE_BLOTS, $this->blotClasses);
        foreach ($validLineBlotClasses as $blotClass) {
            if ($blotClass::matches([$operation])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split normal text operations with newlines inside of them into their own operations.
     *
     * @param $operations The operations in questions.
     */
    private function splitPlainTextNewlines($operations) {
        $newOperations = [];
        foreach ($operations as $opIndex => $op) {
            // Other types of inserts should not get split, they need special handling inside of their blot.
            // Just skip over them.
            if (!$this->isOperationBareInsert($op)) {
                $newOperations[] = $op;
                continue;
            }

            // Split up into an array of newlines (individual) and groups of all other characters.
            preg_match_all("/(\\n)|([^\\n]+)/", $op["insert"], $matches);
            $subInserts = $matches[0];
            if (count($subInserts) <= 1) {
                $newOperations[] = $op;
                continue;
            }

            // If we are going from a non-bare insert to a bare on and we have a newline we need to add an empty break.
            $prevOp = $operations[$opIndex - 1] ?? [];
            $needsExtraBreak = $this->isOperationBareInsert($op) && $this->isOpALineBlotTerminator($prevOp) && $subInserts[0] !== "\n";
            if ($needsExtraBreak) {
                $newOperations[] = static::BREAK_OPERATION;
            }

            // Make an operation for each sub insert.
            foreach ($subInserts as $subInsert) {
                $newOperations[] = ["insert" => $subInsert];
            }
        }

        return $newOperations;
    }

    /**
     * Replace certain newline characters with a constant the does nothing except for break groups up.
     * This is used later on in parsing.
     *
     * @param array $operations The array of operations to look through.
     */
    private function insertBreakPoints(array &$operations) {
        $lastOpEndsInNewline = false;
        foreach ($operations as $opIndex => $op) {
            $isBareInsert = $this->isOperationBareInsert($op);
            $opIsPlainTextNewline = $isBareInsert && $op["insert"] === "\n";
            $opEndsInNewline = is_string($op["insert"] ?? null) && preg_match("/\\n$/", $op["insert"]);

            if ($opIsPlainTextNewline && !$lastOpEndsInNewline) {
                $operations[$opIndex] = static::BREAK_OPERATION;
            }

            $lastOpEndsInNewline = $opEndsInNewline;
        }
    }
}
